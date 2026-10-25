<?php

declare(strict_types=1);

/*
 * SEVALIA — recuperare de urgență, INDEPENDENTĂ de aplicație (fără framework, fără vendor/).
 *
 * Funcționează și când aplicația nu mai pornește după o actualizare. Permite, după autentificarea cu cheia de
 * recuperare (generată la instalare, stocată doar ca hash în <deploy>/shared/keys/recovery.hash):
 *   - vizualizarea stării (versiunea activă, versiunile disponibile, mentenanța, jurnalul ultimei actualizări);
 *   - comutarea la o versiune existentă din releases/ (de obicei cea anterioară);
 *   - pornirea / oprirea modului mentenanță.
 * NU restaurează baza de date (operație cu risc de pierdere de date, făcută din aplicație sau din CLI).
 *
 * Dezactivare: creați fișierul <deploy>/shared/keys/recovery.disabled.
 */

final class SevaliaRecovery
{
    public const RELEASE_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';

    private const MAX_FAILURES_PER_IP = 5;

    private const MAX_FAILURES_GLOBAL = 20;

    private const WINDOW = 900;

    private const SESSION_TTL = 900;

    public function __construct(private readonly string $base) {}

    public static function defaultBase(): string
    {
        return getenv('SEVALIA_DEPLOY_PATH') ?: dirname(__DIR__).'/sevalia';
    }

    private function shared(string $sub = ''): string
    {
        return $this->base.'/shared'.($sub !== '' ? '/'.$sub : '');
    }

    public function isEnabled(): bool
    {
        return is_file($this->shared('keys/recovery.hash')) && ! is_file($this->shared('keys/recovery.disabled'));
    }

    /** Verifică cheia, cu limitare a încercărilor (pe IP și global) printr-un fișier blocat exclusiv. */
    public function checkKey(string $key, string $ip): bool
    {
        $file = $this->shared('keys/recovery.attempts.json');
        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return false;
        }
        flock($fh, LOCK_EX);
        try {
            $data = json_decode((string) stream_get_contents($fh), true);
            $data = is_array($data) ? $data : [];
            $now = time();
            $ipKey = hash('sha256', $ip);
            foreach ($data as $k => $times) {
                $data[$k] = array_values(array_filter((array) $times, fn ($t) => $t > $now - self::WINDOW));
            }
            $global = array_sum(array_map('count', $data));
            if (count($data[$ipKey] ?? []) >= self::MAX_FAILURES_PER_IP || $global >= self::MAX_FAILURES_GLOBAL) {
                $this->log('incercare_blocata', $ip);

                return false;
            }
            $hash = trim((string) @file_get_contents($this->shared('keys/recovery.hash')));
            $ok = $hash !== '' && password_verify(strtoupper(trim($key)), $hash);
            if (! $ok) {
                $data[$ipKey][] = $now;
                $this->log('cheie_gresita', $ip);
            } else {
                unset($data[$ipKey]);
                $this->log('autentificat', $ip);
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($data));

            return $ok;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function isBlocked(string $ip): bool
    {
        $data = json_decode((string) @file_get_contents($this->shared('keys/recovery.attempts.json')), true);
        if (! is_array($data)) {
            return false;
        }
        $recent = fn ($times) => count(array_filter((array) $times, fn ($t) => $t > time() - self::WINDOW));

        return $recent($data[hash('sha256', $ip)] ?? []) >= self::MAX_FAILURES_PER_IP
            || array_sum(array_map($recent, $data)) >= self::MAX_FAILURES_GLOBAL;
    }

    public function status(): array
    {
        $pointer = json_decode((string) @file_get_contents($this->base.'/current.json'), true);
        $releases = [];
        foreach (@scandir($this->base.'/releases') ?: [] as $entry) {
            if (preg_match(self::RELEASE_PATTERN, $entry) && is_dir($this->base.'/releases/'.$entry)) {
                $releases[] = ['version' => $entry, 'complete' => is_file($this->base.'/releases/'.$entry.'/vendor/autoload.php')];
            }
        }
        usort($releases, fn ($a, $b) => version_compare($a['version'], $b['version']));

        $last = json_decode((string) @file_get_contents($this->shared('updates/last.json')), true);
        $journal = null;
        if (is_array($last) && preg_match('/^[0-9a-f-]{36}$/', (string) ($last['uuid'] ?? ''))) {
            $journal = json_decode((string) @file_get_contents($this->shared('updates/'.$last['uuid'].'/journal.json')), true);
        }

        return [
            'current' => is_array($pointer) ? ($pointer['release'] ?? null) : null,
            'previous' => is_array($pointer) ? ($pointer['previous'] ?? null) : null,
            'activated_at' => is_array($pointer) ? ($pointer['activated_at'] ?? null) : null,
            'releases' => $releases,
            'maintenance' => is_file($this->shared('storage/framework/down')),
            'journal' => is_array($journal) ? $journal : null,
        ];
    }

    public function switchRelease(string $release, string $ip): void
    {
        if (! preg_match(self::RELEASE_PATTERN, $release) || ! is_file($this->base.'/releases/'.$release.'/vendor/autoload.php')) {
            throw new RuntimeException('Versiunea aleasă nu există sau este incompletă.');
        }
        $current = $this->status()['current'];
        $payload = json_encode(['release' => $release, 'previous' => $current, 'activated_at' => gmdate('c'), 'by' => 'recovery.php'], JSON_PRETTY_PRINT);
        $tmp = $this->base.'/current.json.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload, LOCK_EX) === false || ! rename($tmp, $this->base.'/current.json')) {
            @unlink($tmp);
            throw new RuntimeException('Comutarea versiunii a eșuat (permisiuni?).');
        }
        $this->log('comutare_versiune', $ip, ['de_la' => $current, 'la' => $release]);
    }

    public function setMaintenance(bool $on, string $ip): void
    {
        $dir = $this->shared('storage/framework');
        if ($on) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir.'/down', json_encode([
                'except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => 60, 'secret' => null,
                'status' => 503, 'template' => null, 'time' => time(),
            ]));
        } else {
            @unlink($dir.'/down');
            @unlink($dir.'/maintenance.php');
        }
        $this->log($on ? 'mentenanta_pornita' : 'mentenanta_oprita', $ip);
    }

    public function log(string $event, string $ip, array $context = []): void
    {
        $dir = $this->shared('updates');
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir.'/recovery.log', json_encode(['at' => gmdate('c'), 'event' => $event, 'ip_hash' => substr(hash('sha256', $ip), 0, 16)] + $context, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }

    // ------------------------------------------------------------------ interfața web

    public function handle(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Robots-Tag: noindex, nofollow');

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        if (! $https && ! in_array($ip, ['127.0.0.1', '::1'], true) && getenv('SEVALIA_RECOVERY_ALLOW_HTTP') !== '1') {
            $this->page('Conexiune nesigură', '<p>Recuperarea funcționează numai prin HTTPS.</p>');

            return;
        }
        if (! $this->isEnabled()) {
            http_response_code(404);
            $this->page('Indisponibil', '<p>Recuperarea nu este activă pe acest server.</p>');

            return;
        }

        session_name('sevalia_recovery');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $authed = isset($_SESSION['authed_at']) && time() - (int) $_SESSION['authed_at'] < self::SESSION_TTL;

        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
                http_response_code(419);
                $this->page('Sesiune expirată', '<p>Reîncărcați pagina și reîncercați.</p>');

                return;
            }
            $action = (string) ($_POST['action'] ?? '');
            try {
                if ($action === 'login') {
                    if ($this->isBlocked($ip)) {
                        $message = 'Prea multe încercări greșite. Așteptați 15 minute.';
                    } elseif ($this->checkKey((string) ($_POST['key'] ?? ''), $ip)) {
                        session_regenerate_id(true);
                        $_SESSION['authed_at'] = time();
                        $authed = true;
                    } else {
                        $message = 'Cheia de recuperare nu este corectă.';
                    }
                } elseif (! $authed) {
                    $message = 'Autentificați-vă din nou.';
                } elseif ($action === 'switch') {
                    $this->switchRelease((string) ($_POST['release'] ?? ''), $ip);
                    $message = 'Versiunea activă a fost schimbată.';
                } elseif ($action === 'maintenance_off') {
                    $this->setMaintenance(false, $ip);
                    $message = 'Modul mentenanță a fost oprit.';
                } elseif ($action === 'maintenance_on') {
                    $this->setMaintenance(true, $ip);
                    $message = 'Modul mentenanță a fost pornit.';
                } elseif ($action === 'logout') {
                    $_SESSION = [];
                    session_destroy();
                    $authed = false;
                    $message = 'V-ați deconectat.';
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }

        $csrf = htmlspecialchars((string) ($_SESSION['csrf'] ?? ''), ENT_QUOTES);
        $msg = $message !== '' ? '<p class="msg">'.htmlspecialchars($message, ENT_QUOTES).'</p>' : '';

        if (! $authed) {
            $this->page('Recuperare SEVALIA', $msg.'<p>Introduceți cheia de recuperare primită la instalare.</p>'
                .'<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="login">'
                .'<label for="key">Cheie de recuperare</label><input id="key" name="key" type="password" autocomplete="off" required autofocus>'
                .'<button type="submit">Intră</button></form>');

            return;
        }

        $s = $this->status();
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $lastRun = $s['journal']['run'] ?? [];
        $rows = '';
        foreach ($s['releases'] as $r) {
            $isCurrent = $r['version'] === $s['current'];
            $failedTarget = ($lastRun['to_version'] ?? null) === $r['version'] && ($lastRun['status'] ?? null) !== 'completed';
            $btn = (! $isCurrent && $r['complete'])
                ? '<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="switch"><input type="hidden" name="release" value="'.$h($r['version']).'"><button type="submit">Activează</button></form>'
                : ($isCurrent ? '<strong>activă</strong>' : 'incompletă');
            $note = $r['version'] === $s['previous'] ? ' (anterioară)' : ($failedTarget && ! $isCurrent ? ' <span class="err">(instalare neîncheiată — nu o activați fără analiză)</span>' : '');
            $rows .= '<tr><td>'.$h($r['version']).$note.'</td><td>'.$btn.'</td></tr>';
        }
        $journal = '';
        if ($s['journal']) {
            $run = $s['journal']['run'] ?? [];
            $statuses = ['prepared' => 'pregătită', 'running' => 'în curs', 'failed' => 'eșuată — necesită decizie', 'completed' => 'încheiată', 'rolled_back' => 'revenită la versiunea anterioară', 'cancelled' => 'anulată'];
            $journal .= '<p>Ultima actualizare: '.$h($run['from_version'] ?? '?').' → '.$h($run['to_version'] ?? '?').', stare <strong>'.$h($statuses[$run['status'] ?? ''] ?? ($run['status'] ?? '?')).'</strong>'
                .(! empty($run['error']) ? '<br><span class="err">'.nl2br($h($run['error'])).'</span>' : '').'</p><ul>';
            foreach (array_slice(array_reverse($s['journal']['events'] ?? []), 0, 20) as $e) {
                $journal .= '<li><code>'.$h($e['at'] ?? '').'</code> '.$h(self::eventLabel((string) ($e['event'] ?? ''))).(! empty($e['eroare']) ? ' — '.$h($e['eroare']) : '').'</li>';
            }
            $journal .= '</ul>';
        }
        $maint = $s['maintenance']
            ? '<p>Mentenanță: <strong>PORNITĂ</strong></p><form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="maintenance_off"><button type="submit">Oprește mentenanța</button></form>'
            : '<p>Mentenanță: oprită</p><form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="maintenance_on"><button type="submit">Pornește mentenanța</button></form>';

        $this->page('Recuperare SEVALIA', $msg
            .'<p>Versiune activă: <strong>'.$h($s['current'] ?? 'necunoscută').'</strong>'.($s['activated_at'] ? ' din '.$h($s['activated_at']) : '').'</p>'
            .'<h2>Versiuni disponibile</h2><table>'.$rows.'</table>'
            .'<p class="note">Comutarea schimbă doar codul. Dacă o actualizare a modificat baza de date, codul vechi poate necesita restaurarea bazei (din aplicație sau din linia de comandă: <code>php artisan sevalia:update:run &lt;id&gt; --restore-db</code>).</p>'
            .'<h2>Mentenanță</h2>'.$maint
            .'<h2>Jurnal</h2>'.($journal ?: '<p>Nicio actualizare înregistrată.</p>')
            .'<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="logout"><button type="submit">Ieșire</button></form>');
    }

    public static function eventLabel(string $event): string
    {
        $labels = [
            'pachet_incarcat' => 'pachet încărcat și verificat', 'pornit' => 'instalare pornită',
            'mentenanta_pornita' => 'mentenanță pornită', 'mentenanta_oprita' => 'mentenanță oprită',
            'versiune_activata' => 'versiune activată', 'cod_revenit' => 'cod revenit la versiunea anterioară',
            'esec' => 'eșec', 'reluare' => 'pas reluat', 'restaurare_bd_pornita' => 'restaurarea bazei pornită',
            'restaurare_bd_finalizata' => 'restaurarea bazei încheiată', 'inchis_manual' => 'incident închis manual',
            'anulat' => 'anulat', 'finalizat' => 'instalare încheiată',
        ];

        return $labels[$event] ?? (str_starts_with($event, 'pas_') ? 'pas încheiat: '.str_replace('_', ' ', substr($event, 4)) : str_replace('_', ' ', $event));
    }

    private function page(string $title, string $body): void
    {
        $t = htmlspecialchars($title, ENT_QUOTES);
        echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>'.$t.'</title>'
            .'<style>body{font-family:system-ui,sans-serif;color:#0e3b3d;background:#eef6f6;margin:0;padding:2rem 1rem}main{max-width:46rem;margin:0 auto;background:#fff;border:1px solid #d6e4e4;border-radius:10px;padding:1.5rem 1.75rem}'
            .'h1{margin-top:0;font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.75rem}table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #d6e4e4;padding:.5rem}'
            .'input{display:block;width:100%;padding:.55rem;margin:.3rem 0 1rem;border:1px solid #b9cfcf;border-radius:6px;font:inherit}button{background:#006f75;color:#fff;border:0;border-radius:6px;padding:.55rem 1rem;font:inherit;cursor:pointer}'
            .'form{margin:.4rem 0}.msg{background:#fdf1dc;padding:.6rem .8rem;border-radius:6px}.err{color:#b42318}.note{font-size:.9rem;color:#3c5f61}code{font-size:.85rem}</style></head>'
            .'<body><main><h1>'.$t.'</h1>'.$body.'</main></body></html>';
    }
}

if (! defined('SEVALIA_RECOVERY_NO_RUN')) {
    (new SevaliaRecovery(SevaliaRecovery::defaultBase()))->handle();
}
