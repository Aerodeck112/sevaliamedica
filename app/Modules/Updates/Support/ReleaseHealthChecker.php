<?php

namespace App\Modules\Updates\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verificări după activare:
 *  1. structura versiunii (autoload, bootstrap, VERSION identic cu manifestul);
 *  2. `php <release>/artisan sevalia:health --json` într-un proces separat (dacă proc_open e permis), adică
 *     noul cod chiar pornește, se conectează la bază și nu are migrări rămase;
 *  3. opțional, un GET HTTP pe SEVALIA_HEALTH_URL (doar după ieșirea din mentenanță are sens; aici se acceptă 503).
 */
class ReleaseHealthChecker implements HealthChecker
{
    public function check(string $releasePath, string $expectedVersion): array
    {
        $results = [];
        foreach (['vendor/autoload.php', 'bootstrap/app.php', 'artisan', 'VERSION'] as $file) {
            $results[] = ['check' => "fișier {$file}", 'ok' => is_file($releasePath.'/'.$file), 'detail' => ''];
        }
        $version = trim((string) @file_get_contents($releasePath.'/VERSION'));
        $results[] = ['check' => 'versiune', 'ok' => $version === $expectedVersion, 'detail' => "găsit „{$version}”, așteptat „{$expectedVersion}”"];

        if (function_exists('proc_open') && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $results[] = $this->artisanHealth($releasePath);
        } else {
            $results[] = ['check' => 'pornirea noului cod', 'ok' => true, 'detail' => 'proc_open dezactivat pe hosting: verificați manual pagina de autentificare după ieșirea din mentenanță'];
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

    private function artisanHealth(string $releasePath): array
    {
        $cmd = [PHP_BINARY, $releasePath.'/artisan', 'sevalia:health', '--json'];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $releasePath);
        if (! is_resource($proc)) {
            return ['check' => 'pornirea noului cod', 'ok' => false, 'detail' => 'nu pot porni procesul de verificare'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + 60;
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
            proc_terminate($proc);
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
            'detail' => $ok ? 'conexiune BD, migrări, stocare: în regulă' : mb_substr(trim($err ?: $out) ?: 'cod ieșire '.$code, 0, 500),
        ];
    }
}
