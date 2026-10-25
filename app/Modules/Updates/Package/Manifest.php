<?php

namespace App\Modules\Updates\Package;

use App\Modules\Updates\Support\DeploymentLayout;

/**
 * Manifestul unui pachet (manifest.json). Validat strict: orice câmp lipsă sau de tip greșit respinge pachetul.
 */
final class Manifest
{
    public const FORMAT = 1;

    public const PRODUCT = 'sevalia';

    /**
     * @param  list<array{path: string, sha256: string, size: int}>  $files
     * @param  list<string>  $migrations
     */
    private function __construct(
        public readonly string $raw,
        public readonly string $version,
        public readonly string $type,
        public readonly ?string $upgradeFromMin,
        public readonly ?string $upgradeFromMax,
        public readonly string $phpMin,
        public readonly array $extensions,
        public readonly array $databases,
        public readonly array $files,
        public readonly array $migrations,
        public readonly string $changelog,
        public readonly string $createdAt,
        public readonly array $builder,
    ) {}

    public static function parse(string $raw): self
    {
        if (strlen($raw) > 50 * 1024 * 1024) {
            throw new PackageException('Manifestul este prea mare.');
        }
        try {
            $d = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PackageException('Manifestul nu este JSON valid.');
        }
        if (! is_array($d)) {
            throw new PackageException('Manifestul nu are structura așteptată.');
        }
        if (($d['format'] ?? null) !== self::FORMAT || ($d['product'] ?? null) !== self::PRODUCT) {
            throw new PackageException('Pachetul nu este un pachet SEVALIA compatibil (format sau produs necunoscut).');
        }

        $version = self::str($d, 'version');
        if (! preg_match(DeploymentLayout::RELEASE_PATTERN, $version)) {
            throw new PackageException('Versiunea din manifest nu este validă.');
        }
        $type = self::str($d, 'type');
        if (! in_array($type, ['install', 'update'], true)) {
            throw new PackageException('Tipul pachetului trebuie să fie „install” sau „update”.');
        }

        $from = $d['upgrade_from'] ?? null;
        $min = $max = null;
        if ($type === 'update') {
            if (! is_array($from) || ! isset($from['min'], $from['max']) || ! is_string($from['min']) || ! is_string($from['max'])) {
                throw new PackageException('Pachetul de actualizare nu declară versiunile de la care se poate actualiza.');
            }
            [$min, $max] = [$from['min'], $from['max']];
        }

        $req = $d['requires'] ?? null;
        if (! is_array($req) || ! is_string($req['php'] ?? null) || ! is_array($req['extensions'] ?? null) || ! is_array($req['database'] ?? null)) {
            throw new PackageException('Manifestul nu declară cerințele (PHP, extensii, bază de date).');
        }

        $files = [];
        $seen = [];
        foreach ((array) ($d['files'] ?? []) as $f) {
            if (! is_array($f) || ! is_string($f['path'] ?? null) || ! is_string($f['sha256'] ?? null) || ! is_int($f['size'] ?? null)) {
                throw new PackageException('Lista de fișiere din manifest are o intrare invalidă.');
            }
            if (! preg_match('/^[a-f0-9]{64}$/', $f['sha256']) || $f['size'] < 0) {
                throw new PackageException('Suma de control sau dimensiunea invalidă pentru '.$f['path']);
            }
            if (! str_starts_with($f['path'], 'payload/')) {
                throw new PackageException('Fișier în afara payload/: '.$f['path']);
            }
            if (isset($seen[$f['path']])) {
                throw new PackageException('Fișier duplicat în manifest: '.$f['path']);
            }
            $seen[$f['path']] = true;
            $files[] = ['path' => $f['path'], 'sha256' => $f['sha256'], 'size' => $f['size']];
        }
        if ($files === []) {
            throw new PackageException('Manifestul nu conține fișiere.');
        }

        $migrations = array_values(array_filter((array) ($d['migrations'] ?? []), 'is_string'));

        return new self(
            $raw, $version, $type, $min, $max, $req['php'],
            array_values(array_filter($req['extensions'], 'is_string')), $req['database'],
            $files, $migrations, (string) ($d['changelog'] ?? ''), (string) ($d['created_at'] ?? ''),
            is_array($d['builder'] ?? null) ? $d['builder'] : [],
        );
    }

    /** Se poate instala peste versiunea curentă? Returnează motivul refuzului sau null. */
    public function incompatibilityWith(?string $current): ?string
    {
        if ($this->type === 'install') {
            return $current === null ? null : 'Pachetul este de instalare completă; pentru o aplicație existentă folosiți un pachet de actualizare.';
        }
        if ($current === null) {
            return 'Versiunea curentă nu poate fi determinată.';
        }
        if (version_compare($this->version, $current, '<=')) {
            return "Versiunea pachetului ({$this->version}) nu este mai nouă decât versiunea curentă ({$current}).";
        }
        if (version_compare($current, (string) $this->upgradeFromMin, '<') || version_compare($current, (string) $this->upgradeFromMax, '>')) {
            return "Pachetul se poate instala doar peste versiunile {$this->upgradeFromMin} – {$this->upgradeFromMax}; versiunea curentă este {$current}.";
        }

        return null;
    }

    public function totalBytes(): int
    {
        return array_sum(array_column($this->files, 'size'));
    }

    /** @return array<string, array{path: string, sha256: string, size: int}> */
    public function filesByPath(): array
    {
        return array_column($this->files, null, 'path');
    }

    private static function str(array $d, string $key): string
    {
        if (! is_string($d[$key] ?? null) || $d[$key] === '') {
            throw new PackageException("Câmpul „{$key}” lipsește din manifest.");
        }

        return $d[$key];
    }
}
