<?php

namespace App\Modules\Updates\Package;

use ZipArchive;

/**
 * Inspectează un pachet ZIP fără să-l extragă: structura intrărilor (zip-slip, symlinkuri, tipuri speciale),
 * limitele (număr, dimensiune, raport de compresie), manifestul și semnătura, corespondența exactă dintre
 * intrările din arhivă și lista din manifest.
 */
class PackageInspector
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly array $limits,
        private readonly array $payloadRoots,
    ) {}

    public static function fromConfig(): self
    {
        return new self(SignatureVerifier::fromConfig(), config('updates.limits'), config('updates.payload_roots'));
    }

    public function inspect(string $zipPath): InspectedPackage
    {
        if (! is_file($zipPath)) {
            throw new PackageException('Fișierul pachetului nu există.');
        }
        if (filesize($zipPath) > $this->limits['max_package_bytes']) {
            throw new PackageException('Pachetul depășește dimensiunea maximă permisă.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new PackageException('Fișierul nu este o arhivă ZIP validă sau este corupt.');
        }

        try {
            if ($zip->numFiles > $this->limits['max_entries']) {
                throw new PackageException('Pachetul conține prea multe fișiere ('.$zip->numFiles.').');
            }

            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                if ($stat === false) {
                    throw new PackageException('Intrare ilizibilă în arhivă.');
                }
                $name = $stat['name'];
                $this->assertSafeName($name);
                $isDir = str_ends_with($name, '/');

                $opsys = $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX) {
                    $type = ($attr >> 16) & 0170000;
                    if ($type === 0120000) {
                        throw new PackageException('Pachetul conține o legătură simbolică ('.$name.'), ceea ce nu este permis.');
                    }
                    if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                        throw new PackageException('Pachetul conține un fișier special nepermis ('.$name.').');
                    }
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw new PackageException('Pachetul conține intrări criptate, care nu sunt permise.');
                }

                if ($isDir) {
                    continue;
                }
                $size = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];
                if ($size > $this->limits['max_entry_bytes']) {
                    throw new PackageException('Fișierul '.$name.' depășește dimensiunea maximă permisă.');
                }
                if ($size > 1024 * 1024 && $compressed > 0 && $size / $compressed > $this->limits['max_compression_ratio']) {
                    throw new PackageException('Raport de compresie suspect pentru '.$name.' (posibilă „zip bomb”).');
                }
                if ($size > 0 && $compressed === 0) {
                    throw new PackageException('Dimensiune comprimată invalidă pentru '.$name.'.');
                }
                $total += $size;
                if ($total > $this->limits['max_uncompressed_bytes']) {
                    throw new PackageException('Conținutul dezarhivat depășește limita permisă (posibilă „zip bomb”).');
                }
                if (isset($entries[$name])) {
                    throw new PackageException('Intrare duplicată în arhivă: '.$name);
                }
                $entries[$name] = $size;
            }

            $manifestBytes = $zip->getFromName('manifest.json');
            if ($manifestBytes === false) {
                throw new PackageException('Pachetul nu conține manifest.json.');
            }
            $signature = $zip->getFromName('manifest.sig');
            if ($signature === false) {
                throw new PackageException('Pachetul nu este semnat (lipsește manifest.sig).');
            }

            // Întâi semnătura (peste octeții exacți), abia apoi interpretarea conținutului.
            $keyId = $this->verifier->verify($manifestBytes, $signature);
            $manifest = Manifest::parse($manifestBytes);

            unset($entries['manifest.json'], $entries['manifest.sig']);
            $declared = $manifest->filesByPath();
            foreach ($entries as $name => $size) {
                if (! isset($declared[$name])) {
                    throw new PackageException('Arhiva conține un fișier nedeclarat în manifest: '.$name);
                }
                if ($declared[$name]['size'] !== $size) {
                    throw new PackageException('Dimensiunea fișierului '.$name.' nu corespunde manifestului.');
                }
            }
            foreach ($declared as $name => $_) {
                if (! isset($entries[$name])) {
                    throw new PackageException('Fișier declarat în manifest, dar lipsă din arhivă: '.$name);
                }
            }

            return new InspectedPackage($zipPath, $manifest, $keyId, hash_file('sha256', $zipPath));
        } finally {
            $zip->close();
        }
    }

    public function assertSafeName(string $name): void
    {
        if ($name === '' || strlen($name) > 400) {
            throw new PackageException('Nume de fișier invalid în arhivă.');
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $name)) {
            throw new PackageException('Nume de fișier cu caractere nepermise în arhivă.');
        }
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            throw new PackageException('Cale absolută nepermisă în arhivă: '.$name);
        }
        $segments = explode('/', rtrim($name, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new PackageException('Cale nepermisă în arhivă (traversare de directoare): '.$name);
            }
        }
        if (in_array($name, ['manifest.json', 'manifest.sig'], true) || $name === 'payload/') {
            return;
        }
        if ($segments[0] !== 'payload' || ! isset($segments[1]) || ! in_array($segments[1], $this->payloadRoots, true)) {
            throw new PackageException('Cale în afara directoarelor permise: '.$name);
        }
    }
}
