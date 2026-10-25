<?php

namespace App\Modules\Updates\Package;

use ZipArchive;

/**
 * Extrage un pachet deja inspectat în directorul de staging, pe bucăți (reluabil), verificând pentru fiecare
 * fișier dimensiunea reală (nu doar cea declarată) și suma SHA-256 din manifest.
 */
class PackageExtractor
{
    public function __construct(private readonly PackageInspector $inspector) {}

    /**
     * @return array{cursor: int, done: bool, files: int}
     */
    public function extract(InspectedPackage $package, string $stagingDir, int $cursor, float $deadline): array
    {
        if (! is_dir($stagingDir) && ! mkdir($stagingDir, 0755, true)) {
            throw new PackageException('Nu pot crea directorul de staging.');
        }
        $root = realpath($stagingDir);
        $declared = $package->manifest->filesByPath();

        $zip = new ZipArchive;
        if ($zip->open($package->path, ZipArchive::RDONLY) !== true) {
            throw new PackageException('Pachetul nu mai poate fi deschis.');
        }
        try {
            // Pachetul nu trebuie să se fi schimbat între inspecție și extragere.
            if (hash_file('sha256', $package->path) !== $package->sha256) {
                throw new PackageException('Fișierul pachetului s-a modificat după verificare.');
            }
            $files = 0;
            for ($i = $cursor; $i < $zip->numFiles; $i++) {
                if (microtime(true) > $deadline && $i > $cursor) {
                    return ['cursor' => $i, 'done' => false, 'files' => $files];
                }
                $name = $zip->getNameIndex($i, ZipArchive::FL_UNCHANGED);
                $this->inspector->assertSafeName($name);
                if (str_ends_with($name, '/') || ! isset($declared[$name])) {
                    continue; // directoare; manifest.json/.sig nu se extrag
                }
                $target = $root.'/'.$name;
                $dir = dirname($target);
                if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
                    throw new PackageException('Nu pot crea directorul '.$dir);
                }
                $realDir = realpath($dir);
                if ($realDir === false || ! str_starts_with($realDir.'/', $root.'/')) {
                    throw new PackageException('Țintă de extragere în afara directorului de staging: '.$name);
                }
                if (is_link($target)) {
                    throw new PackageException('Ținta de extragere este o legătură simbolică: '.$name);
                }

                $this->extractOne($zip, $name, $target, $declared[$name]);
                $files++;
            }

            return ['cursor' => $zip->numFiles, 'done' => true, 'files' => $files];
        } finally {
            $zip->close();
        }
    }

    private function extractOne(ZipArchive $zip, string $name, string $target, array $expected): void
    {
        $in = $zip->getStream($name);
        if ($in === false) {
            throw new PackageException('Nu pot citi '.$name.' din arhivă.');
        }
        $tmp = $target.'.part';
        $out = fopen($tmp, 'wb');
        $hash = hash_init('sha256');
        $written = 0;
        try {
            while (! feof($in)) {
                $chunk = fread($in, 1 << 16);
                if ($chunk === false) {
                    throw new PackageException('Eroare la citirea '.$name.'.');
                }
                $written += strlen($chunk);
                if ($written > $expected['size']) {
                    throw new PackageException('Fișierul '.$name.' este mai mare decât declară manifestul.');
                }
                hash_update($hash, $chunk);
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($written !== $expected['size'] || ! hash_equals($expected['sha256'], hash_final($hash))) {
            @unlink($tmp);
            throw new PackageException('Suma de control nu corespunde pentru '.$name.'. Pachetul a fost modificat.');
        }
        rename($tmp, $target);
    }
}
