<?php

namespace App\Modules\Updates\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Raport al mediului real de rulare (versiuni, extensii, limite, funcții dezactivate, spațiu, bază de date).
 * Folosit de `sevalia:install --check` și afișat în tabul Actualizări.
 */
class EnvironmentReport
{
    public function __construct(
        private readonly DeploymentLayout $layout,
        private readonly DiskSpace $disk,
    ) {}

    /** @return list<array{group: string, label: string, value: string, status: string, hint: string}> */
    public function collect(): array
    {
        $r = [];
        $add = function (string $group, string $label, string $value, string $status = 'ok', string $hint = '') use (&$r) {
            $r[] = compact('group', 'label', 'value', 'status', 'hint');
        };

        $add('PHP', 'Versiune', PHP_VERSION, version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'fail', 'necesar ≥ 8.3');
        $add('PHP', 'SAPI', PHP_SAPI);
        foreach ([...config('updates.required_extensions', []), 'intl', 'gd', 'curl'] as $ext) {
            $optional = in_array($ext, ['intl', 'gd', 'curl'], true);
            $add('Extensii', $ext, extension_loaded($ext) ? 'încărcată' : 'lipsă', extension_loaded($ext) ? 'ok' : ($optional ? 'warn' : 'fail'));
        }
        $add('Extensii', 'GD cu WebP', function_exists('imagewebp') ? 'da' : 'nu', function_exists('imagewebp') ? 'ok' : 'warn', 'necesar pentru imaginile optimizate ale siteului');

        foreach (['memory_limit' => '256M', 'upload_max_filesize' => '64M', 'post_max_size' => '64M', 'max_execution_time' => '30', 'max_input_time' => null, 'max_file_uploads' => null] as $ini => $recommended) {
            $value = (string) ini_get($ini);
            $status = 'ok';
            if ($recommended !== null && $ini !== 'max_execution_time' && self::bytes($value) !== -1 && self::bytes($value) < self::bytes($recommended)) {
                $status = 'warn';
            }
            $add('Limite PHP', $ini, $value === '' ? '—' : $value, $status, $recommended ? "recomandat ≥ {$recommended}" : '');
        }
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        foreach (['proc_open', 'exec', 'shell_exec', 'symlink'] as $fn) {
            $off = in_array($fn, $disabled, true) || ! function_exists($fn);
            $add('Funcții', $fn, $off ? 'dezactivată' : 'disponibilă', $off ? 'warn' : 'ok',
                $fn === 'proc_open' ? 'folosită pentru verificarea noului cod după actualizare (opțională)' : 'nu este necesară');
        }
        $add('Funcții', 'opcache', function_exists('opcache_get_status') && @opcache_get_status(false) ? 'activ' : 'inactiv', 'ok');

        try {
            $db = DB::connection(config('updates.connection'));
            $version = (string) $db->selectOne('SELECT VERSION() AS v')->v;
            $add('Bază de date', 'Server', $version);
            $add('Bază de date', 'Nume', $db->getDatabaseName());
            $charset = (string) ($db->selectOne('SELECT @@character_set_database AS c')->c ?? '');
            $add('Bază de date', 'Set de caractere', $charset, $charset === 'utf8mb4' ? 'ok' : 'warn', 'recomandat utf8mb4');
            $add('Bază de date', 'max_allowed_packet', (string) $db->selectOne('SELECT @@max_allowed_packet AS p')->p);
        } catch (Throwable $e) {
            $add('Bază de date', 'Conexiune', 'eșuată: '.$e->getMessage(), 'fail');
        }

        $free = $this->disk->freeBytes($this->layout->sharedPath());
        $add('Disc', 'Spațiu liber (director shared)', $free === null ? 'nedeterminat' : \App\Modules\Backup\BackupManager::human($free), $free !== null && $free < 1024 ** 3 ? 'warn' : 'ok', 'recomandat ≥ 1 GB pentru actualizări și backup');
        $add('Structură', 'Mod', $this->layout->isDeployed() ? 'releases/ (producție/staging)' : 'checkout de dezvoltare', $this->layout->isDeployed() ? 'ok' : 'warn');
        $add('Structură', 'Director shared', $this->layout->sharedPath(), is_dir($this->layout->sharedPath()) && is_writable($this->layout->sharedPath()) ? 'ok' : 'fail');
        $add('Structură', 'public_html', $this->layout->publicPath() ?? 'neconfigurat (SEVALIA_PUBLIC_PATH)', $this->layout->publicPath() ? 'ok' : 'warn');
        $add('Structură', 'Cheie de backup', \App\Modules\Backup\Crypto\BackupKey::exists($this->layout->keysPath()) ? 'prezentă' : 'lipsă', \App\Modules\Backup\Crypto\BackupKey::exists($this->layout->keysPath()) ? 'ok' : 'warn');
        $add('Aplicație', 'Versiune', (string) config('sevalia.version'));
        $add('Aplicație', 'Mediu', config('app.env').' / '.config('sevalia.stage'), config('app.debug') && config('app.env') === 'production' ? 'fail' : 'ok', 'APP_DEBUG trebuie să fie false în producție');
        $add('Aplicație', 'URL', (string) config('app.url'), str_starts_with((string) config('app.url'), 'https://') || config('app.env') !== 'production' ? 'ok' : 'warn', 'producția trebuie să folosească HTTPS');
        $add('Aplicație', 'Fus orar', (string) config('app.timezone'));
        $add('Aplicație', 'E-mail (MAIL_MAILER)', (string) config('mail.default'), config('mail.default') === 'log' && config('app.env') === 'production' ? 'warn' : 'ok');

        return $r;
    }

    public static function passed(array $report): bool
    {
        return ! in_array('fail', array_column($report, 'status'), true);
    }

    /** Convertește valori ini (ex. 128M) în octeți; -1 = nelimitat. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $n = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
