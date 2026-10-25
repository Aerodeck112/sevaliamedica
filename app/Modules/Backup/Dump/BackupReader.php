<?php

namespace App\Modules\Backup\Dump;

use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Backup\Crypto\EncryptedStream;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Citirea, verificarea și restaurarea unui backup de bază de date.
 */
class BackupReader
{
    /**
     * Verificare completă: decriptează și autentifică fiecare bloc, decomprimă, numără instrucțiunile și le
     * compară cu totalul înregistrat la final. Nu atinge nicio bază de date.
     *
     * @return array{header: array, footer: array, statements: int}
     */
    public function verifyDatabase(string $file, BackupKey $key): array
    {
        $stream = EncryptedStream::openReader($file, $key);
        $header = null;
        $footer = null;
        $statements = 0;
        try {
            while (($msg = $stream->read()) !== null) {
                $type = $msg[0] ?? '';
                $body = substr($msg, 1);
                if ($type === 'H') {
                    $header = json_decode($body, true);
                } elseif ($type === 'S') {
                    $sql = gzinflate($body);
                    if ($sql === false) {
                        throw new RuntimeException('Bloc SQL corupt (decomprimare eșuată).');
                    }
                    $statements += substr_count($sql, "\n");
                } elseif ($type === 'F') {
                    $footer = json_decode($body, true);
                } else {
                    throw new RuntimeException('Tip de bloc necunoscut în backup.');
                }
            }
        } finally {
            $stream->close();
        }
        if (! is_array($header) || ($header['type'] ?? null) !== 'database') {
            throw new RuntimeException('Backupul nu are antet valid.');
        }
        if (! is_array($footer)) {
            throw new RuntimeException('Backupul nu are bloc final.');
        }
        if ((int) $footer['statements'] !== $statements) {
            throw new RuntimeException("Număr de instrucțiuni diferit: {$statements} citite, {$footer['statements']} declarate.");
        }

        return ['header' => $header, 'footer' => $footer, 'statements' => $statements];
    }

    /** Pornește restaurarea în conexiunea țintă; întoarce starea. */
    public function startRestore(string $file, BackupKey $key): array
    {
        $stream = EncryptedStream::openReader($file, $key);
        $msg = $stream->read();
        if (($msg[0] ?? '') !== 'H') {
            throw new RuntimeException('Backupul nu începe cu un antet valid.');
        }
        $state = ['stream' => $stream->toState(), 'executed' => 0, 'done' => false, 'header' => json_decode(substr($msg, 1), true)];
        $stream->close();

        return $state;
    }

    /** Execută instrucțiunile până la $deadline. Fiecare apel își setează propria sesiune SQL. */
    public function stepRestore(string $file, array $state, Connection $target, float $deadline): array
    {
        $stream = EncryptedStream::resumeReader($file, $state['stream']);
        $pdo = $target->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec("SET time_zone='+00:00'");
        try {
            $progressed = false;
            while (! $progressed || microtime(true) < $deadline) {
                $progressed = true;
                $msg = $stream->read();
                if ($msg === null) {
                    break;
                }
                $type = $msg[0];
                if ($type === 'S') {
                    $sql = gzinflate(substr($msg, 1));
                    if ($sql === false) {
                        throw new RuntimeException('Bloc SQL corupt.');
                    }
                    foreach (explode("\n", $sql) as $line) {
                        if (trim($line) !== '') {
                            $pdo->exec($line);
                            $state['executed']++;
                        }
                    }
                } elseif ($type === 'F') {
                    $footer = json_decode(substr($msg, 1), true);
                    if ((int) $footer['statements'] !== $state['executed']) {
                        throw new RuntimeException('Restaurare incompletă: număr de instrucțiuni diferit de cel din backup.');
                    }
                    $state['done'] = true;
                    $state['footer'] = $footer;
                }
            }
            $state['stream'] = $stream->toState();
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $stream->close();
        }

        return $state;
    }
}
