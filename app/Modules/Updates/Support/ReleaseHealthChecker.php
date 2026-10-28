<?php

namespace App\Modules\Updates\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verificări după activare:
 *  1. structura versiunii (autoload, bootstrap, VERSION identic cu manifestul);
 *  2. pornirea noului cod: bază de date, migrări, stocare (vezi AppHealth);
 *  3. opțional, un GET HTTP pe SEVALIA_HEALTH_URL (doar după ieșirea din mentenanță are sens; aici se acceptă 503).
 *
 * Pornirea noului cod se verifică:
 *  - în procesul curent, dacă acesta rulează deja din versiunea nouă (cererea web de după activare: front
 *    controllerul citește current.json la fiecare cerere; la fel, cron-ul pornit după activare);
 *  - din linia de comandă (CLI), cu `php <release>/artisan sevalia:health --json` într-un proces separat;
 *  - altfel (cerere web servită încă de codul vechi), verificarea se amână pentru cererea următoare.
 *
 * Într-o cerere web nu se pornește niciodată PHP_BINARY: pe cPanel acesta este lsphp/php-fpm, nu PHP CLI, iar un
 * astfel de proces poate rămâne agățat (moștenește socketul serverului web) și ține siteul în mentenanță.
 */
class ReleaseHealthChecker implements HealthChecker
{
    public function __construct(private readonly AppHealth $appHealth) {}

    public function check(string $releasePath, string $expectedVersion): array
    {
        $results = [];
        foreach (['vendor/autoload.php', 'bootstrap/app.php', 'artisan', 'VERSION'] as $file) {
            $results[] = ['check' => "fișier {$file}", 'ok' => is_file($releasePath.'/'.$file), 'detail' => ''];
        }
        $version = trim((string) @file_get_contents($releasePath.'/VERSION'));
        $results[] = ['check' => 'versiune', 'ok' => $version === $expectedVersion, 'detail' => "găsit „{$version}”, așteptat „{$expectedVersion}”"];

        if (self::runsFrom($releasePath)) {
            $checks = $this->appHealth->checks();
            $failed = array_filter($checks, fn ($c) => ! $c['ok'] && $c['critical']);
            $results[] = [
                'check' => 'pornirea noului cod',
                'ok' => $failed === [],
                'detail' => $failed === [] ? 'conexiune BD, migrări, stocare: în regulă'
                    : implode('; ', array_map(fn ($c) => $c['check'].': '.$c['detail'], $failed)),
            ];
        } elseif (PHP_SAPI === 'cli' && self::canSpawn()) {
            $results[] = $this->artisanHealth($releasePath);
        } elseif (PHP_SAPI === 'cli') {
            $results[] = ['check' => 'pornirea noului cod', 'ok' => true, 'detail' => 'proc_open dezactivat pe hosting: verificați manual pagina de autentificare după ieșirea din mentenanță'];
        } else {
            $results[] = ['check' => 'pornirea noului cod', 'ok' => false, 'pending' => true, 'detail' => 'se verifică la cererea următoare, servită de noul cod'];
        }

        if ($url = config('updates.health_url')) {
            try {
                $status = Http::timeout(10)->get($url)->status();
                $results[] = ['check' => 'HTTP '.$url, 'ok' => in_array($status, [200, 503], true), 'detail' => "cod {$status}"];
            } catch (Throwable $e) {
                $results[] = ['check' => 'HTTP '.$url, 'ok' => false, 'detail' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** Procesul curent rulează din directorul versiunii verificate. */
    public static function runsFrom(string $releasePath): bool
    {
        $base = realpath(base_path());
        $release = realpath($releasePath);

        return $base !== false && $release !== false && $base === $release;
    }

    private static function canSpawn(): bool
    {
        return function_exists('proc_open')
            && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    private function artisanHealth(string $releasePath): array
    {
        $cmd = [PHP_BINARY, $releasePath.'/artisan', 'sevalia:health', '--json'];
        // stdin propriu (închis imediat): procesul copil nu moștenește descriptorii procesului curent.
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $releasePath);
        if (! is_resource($proc)) {
            return ['check' => 'pornirea noului cod', 'ok' => false, 'detail' => 'nu pot porni procesul de verificare'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + (int) config('updates.health_timeout', 30);
        do {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (! $status['running']) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        if ($status['running']) {
            proc_terminate($proc, 9);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = $status['running'] ? -1 : $status['exitcode'];
        proc_close($proc);

        $json = json_decode(trim($out), true);
        $ok = $code === 0 && is_array($json) && ($json['ok'] ?? false) === true;

        return [
            'check' => 'pornirea noului cod (sevalia:health)',
            'ok' => $ok,
            'detail' => $ok ? 'conexiune BD, migrări, stocare: în regulă'
                : ($code === -1 ? 'verificarea nu a răspuns în timp util' : mb_substr(trim($err ?: $out) ?: 'cod ieșire '.$code, 0, 500)),
        ];
    }
}
