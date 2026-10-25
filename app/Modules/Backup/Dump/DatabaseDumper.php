<?php

namespace App\Modules\Backup\Dump;

use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Backup\Crypto\EncryptedStream;
use Illuminate\Database\Connection;
use PDO;

/**
 * Dump SQL al bazei de date în PHP pur, fără mysqldump: structură (SHOW CREATE TABLE) + date în instrucțiuni
 * INSERT multi-rând, fiecare instrucțiune pe un singur rând. Rulează pe bucăți cu stare salvabilă, deci poate
 * continua în cereri succesive; rezultatul este criptat în flux (EncryptedStream).
 *
 * Mesajele din flux încep cu un octet de tip: H = antet JSON, S = bloc SQL comprimat (deflate), F = final JSON.
 */
class DatabaseDumper
{
    public function __construct(
        private readonly Connection $db,
        private readonly array $structureOnly = [],
        private readonly int $rowsPerInsert = 200,
        private readonly int $rowsPerChunk = 2000,
    ) {}

    /** Inițializează dumpul: scrie antetul și întoarce starea de pornire. */
    public function start(string $file, BackupKey $key, array $meta = []): array
    {
        $tables = array_map(
            fn ($row) => array_values((array) $row)[0],
            $this->db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
        );
        sort($tables);

        $stream = EncryptedStream::create($file, $key);
        $stream->write('H'.json_encode([
            'type' => 'database',
            'format' => 1,
            'database' => $this->db->getDatabaseName(),
            'server_version' => $this->db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            'created_at' => gmdate('c'),
            'tables' => $tables,
        ] + $meta, JSON_UNESCAPED_UNICODE));
        $prologue = [
            '/*!40101 SET NAMES utf8mb4 */;',
            'SET FOREIGN_KEY_CHECKS=0;',
            'SET UNIQUE_CHECKS=0;',
            "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';",
            "SET time_zone='+00:00';",
        ];
        $stream->write('S'.gzdeflate(implode("\n", $prologue)."\n", 6));

        return [
            'tables' => $tables,
            'table' => 0,
            'phase' => 'schema',
            'cursor' => null,
            'offset' => 0,
            'statements' => count($prologue),
            'rows' => [],
            'stream' => $stream->toState(),
            'done' => false,
        ];
    }

    /** Continuă dumpul până la $deadline (microtime). Întoarce starea nouă (done=true la final). */
    public function step(string $file, array $state, float $deadline): array
    {
        $stream = EncryptedStream::resumeWriter($file, $state['stream']);
        $pdo = $this->db->getPdo();
        // Sesiune în UTC pentru valori TIMESTAMP consecvente la restaurare.
        $this->db->statement("SET time_zone='+00:00'");

        try {
            $progressed = false;
            while ($state['table'] < count($state['tables'])) {
                // Cel puțin o unitate de lucru per apel, ca reluarea să avanseze mereu.
                if ($progressed && microtime(true) > $deadline) {
                    break;
                }
                $progressed = true;
                $table = $state['tables'][$state['table']];
                $q = '`'.str_replace('`', '``', $table).'`';

                if ($state['phase'] === 'schema') {
                    $create = (array) $this->db->selectOne("SHOW CREATE TABLE {$q}");
                    $ddl = preg_replace('/\s*\R\s*/', ' ', (string) array_values($create)[1]);
                    $stream->write('S'.gzdeflate("DROP TABLE IF EXISTS {$q};\n{$ddl};\n", 6));
                    $state['statements'] += 2;
                    $state['rows'][$table] = 0;
                    $state['phase'] = in_array($table, $this->structureOnly, true) ? 'next' : 'rows';
                    $state['cursor'] = null;
                    $state['offset'] = 0;

                    continue;
                }

                if ($state['phase'] === 'rows') {
                    [$sql, $count, $state] = $this->rowsChunk($pdo, $table, $q, $state);
                    if ($count > 0) {
                        $stream->write('S'.gzdeflate($sql, 6));
                        $state['rows'][$table] += $count;
                        $state['statements'] += substr_count($sql, "\n");
                    }
                    if ($count < $this->rowsPerChunk) {
                        $state['phase'] = 'next';
                    }

                    continue;
                }

                $state['table']++;
                $state['phase'] = 'schema';
            }

            if ($state['table'] >= count($state['tables'])) {
                $stream->write('S'.gzdeflate("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n", 6));
                $state['statements'] += 2;
                $stream->write('F'.json_encode([
                    'statements' => $state['statements'],
                    'rows' => $state['rows'],
                    'finished_at' => gmdate('c'),
                ]), true);
                $state['done'] = true;
            }
            $state['stream'] = $stream->toState();
        } finally {
            $stream->close();
        }

        return $state;
    }

    /** @return array{0: string, 1: int, 2: array} */
    private function rowsChunk(PDO $pdo, string $table, string $q, array $state): array
    {
        $columns = $this->db->select("SHOW COLUMNS FROM {$q}");
        $binary = [];
        $pk = [];
        foreach ($columns as $c) {
            $type = strtolower($c->Type);
            if (preg_match('/blob|binary|^bit/', $type)) {
                $binary[$c->Field] = true;
            }
            if ($c->Key === 'PRI') {
                $pk[] = ['name' => $c->Field, 'int' => (bool) preg_match('/int\b|int\(/', $type)];
            }
        }

        $limit = $this->rowsPerChunk;
        if (count($pk) === 1 && $pk[0]['int']) {
            $col = '`'.str_replace('`', '``', $pk[0]['name']).'`';
            $rows = $state['cursor'] === null
                ? $pdo->query("SELECT * FROM {$q} ORDER BY {$col} LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)
                : (function () use ($pdo, $q, $col, $limit, $state) {
                    $st = $pdo->prepare("SELECT * FROM {$q} WHERE {$col} > ? ORDER BY {$col} LIMIT {$limit}");
                    $st->execute([$state['cursor']]);

                    return $st->fetchAll(PDO::FETCH_ASSOC);
                })();
            if ($rows) {
                $state['cursor'] = end($rows)[$pk[0]['name']];
            }
        } else {
            $order = $pk ? ' ORDER BY '.implode(', ', array_map(fn ($p) => '`'.str_replace('`', '``', $p['name']).'`', $pk)) : '';
            $offset = (int) $state['offset'];
            $rows = $pdo->query("SELECT * FROM {$q}{$order} LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
            $state['offset'] = $offset + count($rows);
        }

        if (! $rows) {
            return ['', 0, $state];
        }

        $names = '('.implode(', ', array_map(fn ($n) => '`'.str_replace('`', '``', $n).'`', array_keys($rows[0]))).')';
        $sql = '';
        foreach (array_chunk($rows, $this->rowsPerInsert) as $batch) {
            $values = [];
            foreach ($batch as $row) {
                $vals = [];
                foreach ($row as $col => $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (isset($binary[$col])) {
                        $vals[] = $v === '' ? "''" : '0x'.bin2hex($v);
                    } else {
                        $vals[] = $pdo->quote((string) $v);
                    }
                }
                $values[] = '('.implode(',', $vals).')';
            }
            // Ghilimelele PDO (mysql_real_escape_string) escapează \n și \r, deci instrucțiunea rămâne pe un rând.
            $sql .= "INSERT INTO {$q} {$names} VALUES ".implode(',', $values).";\n";
        }

        return [$sql, count($rows), $state];
    }
}
