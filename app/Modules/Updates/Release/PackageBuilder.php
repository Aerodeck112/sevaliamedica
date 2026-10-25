<?php

namespace App\Modules\Updates\Release;

use App\Modules\Updates\Package\Manifest;
use App\Modules\Updates\Package\SignatureVerifier;
use RuntimeException;
use ZipArchive;

/**
 * Construiește un pachet semnat dintr-un director pregătit (conținutul viitorului payload/).
 * Rulează pe mașina de build; cheia privată nu ajunge niciodată pe hosting.
 */
class PackageBuilder
{
    /**
     * @param  array{type: string, version: string, upgrade_from?: array{min: string, max: string}|null, changelog?: string,
     *               php?: string, extensions?: list<string>, database?: array<string, string>, builder?: array}  $meta
     */
    public function build(string $sourceDir, string $outFile, string $secretKey, array $meta): array
    {
        $sourceDir = rtrim($sourceDir, '/');
        $files = [];
        $migrations = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isLink()) {
                throw new RuntimeException('Sursa conține o legătură simbolică: '.$f->getPathname());
            }
            if (! $f->isFile()) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($sourceDir) + 1);
            $files[] = ['path' => 'payload/'.$rel, 'sha256' => hash_file('sha256', $f->getPathname()), 'size' => $f->getSize()];
            if (preg_match('#^database/migrations/([^/]+)\.php$#', $rel, $m)) {
                $migrations[] = $m[1];
            }
        }
        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));
        sort($migrations);

        $manifest = [
            'format' => Manifest::FORMAT,
            'product' => Manifest::PRODUCT,
            'version' => $meta['version'],
            'type' => $meta['type'],
            'upgrade_from' => $meta['type'] === 'update' ? $meta['upgrade_from'] : null,
            'requires' => [
                'php' => $meta['php'] ?? '8.3.0',
                'extensions' => $meta['extensions'] ?? config('updates.required_extensions', []),
                'database' => $meta['database'] ?? ['mariadb' => '10.6', 'mysql' => '8.0'],
            ],
            'files' => $files,
            'migrations' => $migrations,
            'changelog' => $meta['changelog'] ?? '',
            'created_at' => gmdate('c'),
            'builder' => $meta['builder'] ?? [],
        ];
        $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        Manifest::parse($manifestBytes); // validare înainte de semnare

        if (! is_dir(dirname($outFile))) {
            mkdir(dirname($outFile), 0755, true);
        }
        @unlink($outFile);
        $zip = new ZipArchive;
        if ($zip->open($outFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Nu pot crea arhiva '.$outFile);
        }
        $zip->addFromString('manifest.json', $manifestBytes);
        $zip->addFromString('manifest.sig', SignatureVerifier::sign($manifestBytes, $secretKey));
        foreach ($files as $file) {
            $rel = substr($file['path'], strlen('payload/'));
            $zip->addFile($sourceDir.'/'.$rel, $file['path']);
            $mode = ($rel === 'artisan' ? 0100755 : 0100644);
            $zip->setExternalAttributesName($file['path'], ZipArchive::OPSYS_UNIX, $mode << 16);
        }
        if (! $zip->close()) {
            throw new RuntimeException('Scrierea arhivei a eșuat.');
        }
        $sha = hash_file('sha256', $outFile);
        file_put_contents($outFile.'.sha256', $sha.'  '.basename($outFile)."\n");

        return ['file' => $outFile, 'sha256' => $sha, 'files' => count($files), 'bytes' => array_sum(array_column($files, 'size'))];
    }

    public static function loadSecretKey(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('Cheia privată nu există: '.$path);
        }
        $raw = base64_decode(trim((string) file_get_contents($path)), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Cheia privată are un format invalid.');
        }

        return $raw;
    }
}
