<?php

namespace App\Modules\Backup\Dump;

use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Backup\Crypto\EncryptedStream;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Backupul documentelor private (fișiere din storage), criptat în flux și reluabil.
 * Blocuri: H antet JSON · F început fișier (JSON cale/dimensiune) · D date · E sfârșit fișier (JSON sha256) · Z final.
 */
class DocumentsArchiver
{
    private const CHUNK = 1 << 20;

    /** @param  array<string, string>  $roots  eticheta => director absolut */
    public function start(string $file, BackupKey $key, array $roots, array $meta = []): array
    {
        $files = [];
        foreach ($roots as $label => $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && ! $f->isLink()) {
                    $files[] = [$label, substr($f->getPathname(), strlen(rtrim($dir, '/')) + 1)];
                }
            }
        }
        sort($files);
        $stream = EncryptedStream::create($file, $key);
        $stream->write('H'.json_encode(['type' => 'documents', 'format' => 1, 'created_at' => gmdate('c'), 'roots' => array_keys($roots), 'files' => count($files)] + $meta));

        return ['roots' => $roots, 'files' => $files, 'index' => 0, 'pos' => 0, 'bytes' => 0, 'stream' => $stream->toState(), 'done' => false];
    }

    public function step(string $file, array $state, float $deadline): array
    {
        $stream = EncryptedStream::resumeWriter($file, $state['stream']);
        try {
            $progressed = false;
            while ($state['index'] < count($state['files']) && (! $progressed || microtime(true) < $deadline)) {
                $progressed = true;
                [$label, $rel] = $state['files'][$state['index']];
                $path = rtrim($state['roots'][$label], '/').'/'.$rel;
                if (! is_file($path)) {
                    $state['index']++;
                    $state['pos'] = 0;

                    continue; // șters între timp
                }
                if (empty($state['open'])) {
                    $stream->write('F'.json_encode(['root' => $label, 'path' => $rel, 'size' => filesize($path)], JSON_UNESCAPED_UNICODE));
                    $state['open'] = true;
                    $state['hash'] = base64_encode(serialize(hash_init('sha256')));
                }
                $ctx = unserialize(base64_decode($state['hash']), ['allowed_classes' => [\HashContext::class]]);
                $fh = fopen($path, 'rb');
                fseek($fh, $state['pos']);
                $first = true;
                while (! feof($fh) && ($first || microtime(true) < $deadline)) {
                    $first = false;
                    $data = fread($fh, self::CHUNK);
                    if ($data === '' || $data === false) {
                        break;
                    }
                    $stream->write('D'.$data);
                    hash_update($ctx, $data);
                    $state['pos'] += strlen($data);
                    $state['bytes'] += strlen($data);
                }
                $eof = feof($fh) || $state['pos'] >= filesize($path);
                fclose($fh);
                if ($eof) {
                    // Suma se calculează pe datele scrise efectiv în backup, nu pe fișierul de pe disc.
                    $stream->write('E'.json_encode(['sha256' => hash_final($ctx)]));
                    unset($state['hash']);
                    $state['index']++;
                    $state['pos'] = 0;
                    $state['open'] = false;
                } else {
                    $state['hash'] = base64_encode(serialize($ctx));
                }
            }
            if ($state['index'] >= count($state['files'])) {
                $stream->write('Z'.json_encode(['files' => count($state['files']), 'bytes' => $state['bytes']]), true);
                $state['done'] = true;
            }
            $state['stream'] = $stream->toState();
        } finally {
            $stream->close();
        }

        return $state;
    }

    /**
     * Verifică (și opțional extrage în $targetDir) un backup de documente. Sumele SHA-256 se verifică pe date.
     *
     * @return array{files: int, bytes: int}
     */
    public function verify(string $file, BackupKey $key, ?string $targetDir = null): array
    {
        $stream = EncryptedStream::openReader($file, $key);
        $files = 0;
        $bytes = 0;
        $current = null;
        $hash = null;
        $out = null;
        $footer = null;
        try {
            while (($msg = $stream->read()) !== null) {
                $type = $msg[0];
                $body = substr($msg, 1);
                switch ($type) {
                    case 'H':
                        break;
                    case 'F':
                        $current = json_decode($body, true);
                        $hash = hash_init('sha256');
                        if ($targetDir !== null) {
                            $dest = $this->safeTarget($targetDir, $current['root'], $current['path']);
                            @mkdir(dirname($dest), 0750, true);
                            $out = fopen($dest, 'wb');
                        }
                        break;
                    case 'D':
                        if ($current === null) {
                            throw new RuntimeException('Bloc de date fără fișier.');
                        }
                        hash_update($hash, $body);
                        $bytes += strlen($body);
                        if ($out) {
                            fwrite($out, $body);
                        }
                        break;
                    case 'E':
                        $expected = json_decode($body, true)['sha256'] ?? '';
                        if (! hash_equals($expected, hash_final($hash))) {
                            throw new RuntimeException('Sumă de control greșită pentru '.$current['path']);
                        }
                        if ($out) {
                            fclose($out);
                            $out = null;
                        }
                        $files++;
                        $current = null;
                        break;
                    case 'Z':
                        $footer = json_decode($body, true);
                        break;
                    default:
                        throw new RuntimeException('Tip de bloc necunoscut.');
                }
            }
        } finally {
            if ($out) {
                fclose($out);
            }
            $stream->close();
        }
        if (! is_array($footer) || (int) $footer['files'] !== $files) {
            throw new RuntimeException('Backupul de documente este incomplet.');
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function safeTarget(string $targetDir, string $root, string $rel): string
    {
        foreach (explode('/', $root.'/'.$rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || str_contains($seg, "\0")) {
                throw new RuntimeException('Cale invalidă în backupul de documente.');
            }
        }

        return rtrim($targetDir, '/').'/'.$root.'/'.$rel;
    }
}
