<?php

namespace App\Modules\Updates\Support;

use RuntimeException;

/**
 * Căile structurii de deployment și pointerul versiunii active (current.json).
 *
 * current.json se scrie atomic: fișier temporar în același director + rename(). Front controllerul din
 * public_html și recovery.php îl citesc fără framework, deci formatul trebuie să rămână simplu.
 */
class DeploymentLayout
{
    public const RELEASE_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';

    public function __construct(
        private readonly ?string $deployPath,
        private readonly string $sharedPath,
        private readonly ?string $publicPath,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('updates.deploy_path'), config('updates.shared_path'), config('updates.public_path'));
    }

    /** Aplicația rulează din structura releases/ (producție/staging), nu dintr-un checkout de dezvoltare. */
    public function isDeployed(): bool
    {
        return $this->deployPath !== null && is_dir($this->deployPath.'/releases');
    }

    public function deployPath(): string
    {
        if ($this->deployPath === null) {
            throw new RuntimeException('SEVALIA_DEPLOY_PATH nu este configurat: aplicația nu rulează din structura de releases.');
        }

        return $this->deployPath;
    }

    public function sharedPath(string $sub = ''): string
    {
        return $this->sharedPath.($sub !== '' ? '/'.ltrim($sub, '/') : '');
    }

    public function publicPath(): ?string
    {
        return $this->publicPath;
    }

    public function releasesPath(string $release = ''): string
    {
        return $this->deployPath().'/releases'.($release !== '' ? '/'.self::assertRelease($release) : '');
    }

    public function updatesPath(string $sub = ''): string
    {
        return $this->sharedPath('updates'.($sub !== '' ? '/'.ltrim($sub, '/') : ''));
    }

    public function backupsPath(string $sub = ''): string
    {
        return $this->sharedPath('backups'.($sub !== '' ? '/'.ltrim($sub, '/') : ''));
    }

    public function keysPath(string $sub = ''): string
    {
        return $this->sharedPath('keys'.($sub !== '' ? '/'.ltrim($sub, '/') : ''));
    }

    public function pointerFile(): string
    {
        return $this->deployPath().'/current.json';
    }

    /** @return array{release: string, previous: ?string, activated_at: string}|null */
    public function current(): ?array
    {
        if ($this->deployPath === null || ! is_file($this->pointerFile())) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->pointerFile()), true);
        if (! is_array($data) || ! isset($data['release']) || ! preg_match(self::RELEASE_PATTERN, $data['release'])) {
            return null;
        }

        return $data + ['previous' => null, 'activated_at' => null];
    }

    /** Comută versiunea activă. Scrie atomic; păstrează versiunea anterioară pentru revenire. */
    public function activate(string $release, ?string $previous = null): void
    {
        self::assertRelease($release);
        if (! is_file($this->releasesPath($release).'/vendor/autoload.php')) {
            throw new RuntimeException("Versiunea {$release} nu este completă (lipsește vendor/autoload.php).");
        }
        $payload = json_encode([
            'release' => $release,
            'previous' => $previous ?? $this->current()['release'] ?? null,
            'activated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $tmp = $this->pointerFile().'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Nu pot scrie pointerul temporar al versiunii.');
        }
        @chmod($tmp, 0644);
        if (! rename($tmp, $this->pointerFile())) {
            @unlink($tmp);
            throw new RuntimeException('Comutarea atomică a versiunii a eșuat.');
        }
    }

    /** @return list<string> versiunile prezente în releases/, sortate crescător */
    public function releases(): array
    {
        if (! $this->isDeployed()) {
            return [];
        }
        $out = [];
        foreach (scandir($this->releasesPath()) ?: [] as $entry) {
            if (preg_match(self::RELEASE_PATTERN, $entry) && is_dir($this->releasesPath($entry))) {
                $out[] = $entry;
            }
        }
        usort($out, 'version_compare');

        return $out;
    }

    public static function assertRelease(string $release): string
    {
        if (! preg_match(self::RELEASE_PATTERN, $release)) {
            throw new RuntimeException('Numele versiunii nu este valid: '.$release);
        }

        return $release;
    }

    public function ensureSharedDirectories(): void
    {
        foreach (['', 'updates', 'backups', 'keys', 'storage', 'storage/app/private', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
            $path = $this->sharedPath($dir);
            if (! is_dir($path)) {
                mkdir($path, $dir === 'keys' ? 0700 : 0755, true);
            }
        }
        @chmod($this->keysPath(), 0700);
    }
}
