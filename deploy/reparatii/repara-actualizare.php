<?php

/*
 * SEVALIA — deblocarea unei actualizări rămase „în curs” (fără Terminal, fără aplicație).
 *
 * Problema (versiunile ≤ 0.9.3): la pasul „Verificări de funcționare”, actualizatorul pornea un proces PHP separat.
 * Pe hostingurile LiteSpeed/PHP-FPM acel proces nu este PHP CLI și se poate bloca; actualizarea rămâne „în curs”, iar
 * siteul public rămâne în mentenanță (eroare 503).
 *
 * Utilizare: cPanel → File Manager → public_html → Upload acest fișier → deschideți
 * https://<domeniu>/repara-actualizare.php → introduceți CHEIA DE RECUPERARE (primită la instalare) → alegeți:
 *   - „Finalizează actualizarea” — dacă versiunea nouă este deja activă: scriptul verifică el însuși noul cod (fișiere,
 *     baza de date, migrări, stocare) și, doar dacă totul e în regulă, încheie actualizarea și oprește mentenanța;
 *   - „Revino la versiunea anterioară” — doar dacă actualizarea nu a rulat migrări (baza de date neatinsă).
 * Scriptul se șterge singur după o acțiune reușită. Nu restaurează și nu modifică datele clinicii.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

const RELEASE_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';
const SESSION_TTL = 900;

$base = getenv('SEVALIA_DEPLOY_PATH') ?: dirname(__DIR__).'/sevalia';
$shared = $base.'/shared';
$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<meta name="robots" content="noindex,nofollow"><title>'.htmlspecialchars($title, ENT_QUOTES).'</title><style>'
        .'body{font-family:system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;color:#0e3b3d;line-height:1.5}'
        .'h1{font-size:1.4rem}table{border-collapse:collapse;width:100%;font-size:.92rem}td,th{border-bottom:1px solid #d9e3e3;padding:.35rem .4rem;text-align:left;vertical-align:top}'
        .'.ok{color:#17663a}.err{color:#a1261b}.msg{background:#fff6df;border:1px solid #e8c66a;padding:.7rem 1rem;border-radius:.4rem}'
        .'.done{background:#e6f4ea;border:1px solid #8cc9a0;padding:.7rem 1rem;border-radius:.4rem}'
        .'form{margin:1rem 0;padding:1rem;border:1px solid #d9e3e3;border-radius:.5rem}input[type=password]{width:100%;padding:.55rem;font-size:1rem;box-sizing:border-box;margin:.3rem 0 .8rem}'
        .'button{background:#0e5e61;color:#fff;border:0;border-radius:.4rem;padding:.6rem 1.1rem;font-size:1rem;cursor:pointer}button.sec{background:#5b6b6c}'
        .'code{background:#f1f5f5;padding:.05rem .3rem;border-radius:.2rem}</style></head><body><h1>'.htmlspecialchars($title, ENT_QUOTES).'</h1>'.$body.'</body></html>';
    exit;
}

function logEvent(string $shared, string $event, array $context = []): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    @file_put_contents($shared.'/updates/recovery.log', json_encode(['at' => gmdate('c'), 'event' => $event, 'ip_hash' => substr(hash('sha256', $ip), 0, 16), 'by' => 'repara-actualizare.php'] + $context, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
}

/** Aceeași cheie și aceeași limitare a încercărilor ca recovery.php (fișierul de încercări este comun). */
function checkKey(string $shared, string $key): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $fh = fopen($shared.'/keys/recovery.attempts.json', 'c+');
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
            $data[$k] = array_values(array_filter((array) $times, fn ($t) => $t > $now - 900));
        }
        if (count($data[$ipKey] ?? []) >= 5 || array_sum(array_map('count', $data)) >= 20) {
            throw new RuntimeException('Prea multe încercări greșite. Așteptați 15 minute.');
        }
        $hash = trim((string) @file_get_contents($shared.'/keys/recovery.hash'));
        $ok = $hash !== '' && password_verify(strtoupper(trim($key)), $hash);
        if ($ok) {
            unset($data[$ipKey]);
        } else {
            $data[$ipKey][] = $now;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($data));
        logEvent($shared, $ok ? 'repara_autentificat' : 'repara_cheie_gresita');

        return $ok;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Citește valorile DB_* și APP_TIMEZONE din shared/.env (format dotenv simplu). */
function readEnv(string $shared): array
{
    $out = [];
    foreach (@file($shared.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*(DB_[A-Z_]+|APP_TIMEZONE)\s*=\s*(.*)$/', $line, $m)) {
            $v = trim($m[2]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                $v = $v[0] === '"' ? stripcslashes(substr($v, 1, -1)) : substr($v, 1, -1);
            } else {
                $v = preg_replace('/\s+#.*$/', '', $v);
            }
            $out[$m[1]] = $v;
        }
    }

    return $out;
}

function db(array $env): PDO
{
    $dsn = ! empty($env['DB_SOCKET'])
        ? 'mysql:unix_socket='.$env['DB_SOCKET'].';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4'
        : 'mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4';

    return new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
}

function pointer(string $base): array
{
    $p = json_decode((string) @file_get_contents($base.'/current.json'), true);

    return is_array($p) ? $p : [];
}

function writePointer(string $base, string $release, ?string $previous): void
{
    $payload = json_encode(['release' => $release, 'previous' => $previous, 'activated_at' => gmdate('c'), 'by' => 'repara-actualizare.php'], JSON_PRETTY_PRINT);
    $tmp = $base.'/current.json.tmp-'.bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $payload, LOCK_EX) === false || ! rename($tmp, $base.'/current.json')) {
        @unlink($tmp);
        throw new RuntimeException('Nu pot scrie current.json (permisiuni?).');
    }
}

function maintenanceOff(string $shared): void
{
    @unlink($shared.'/storage/framework/down');
    @unlink($shared.'/storage/framework/maintenance.php');
}

function journal(string $shared, array $run, string $event, array $context = []): void
{
    $path = $shared.'/updates/'.$run['uuid'].'/journal.json';
    $data = json_decode((string) @file_get_contents($path), true);
    $data = is_array($data) ? $data : ['events' => []];
    $data['run'] = [
        'id' => (int) $run['id'], 'uuid' => $run['uuid'], 'from_version' => $run['from_version'], 'to_version' => $run['to_version'],
        'status' => $run['status'], 'current_step' => $run['current_step'], 'steps' => $run['steps'], 'error' => $run['error'],
        'backup_id' => $run['backup_id'], 'started_at' => $run['started_at'], 'finished_at' => $run['finished_at'],
    ];
    $data['events'][] = ['at' => gmdate('c'), 'event' => $event] + $context;
    if (! is_dir(dirname($path))) {
        @mkdir(dirname($path), 0750, true);
    }
    $tmp = $path.'.tmp-'.bin2hex(random_bytes(3));
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $path);
}

function removeDir(string $dir): void
{
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);

        return;
    }
    if (! is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Verificările noului cod, făcute direct (fără a porni aplicația). */
function verifyRelease(string $base, string $shared, PDO $pdo, string $version): array
{
    $rel = $base.'/releases/'.$version;
    $checks = [];
    foreach (['vendor/autoload.php', 'bootstrap/app.php', 'artisan', 'VERSION'] as $f) {
        $checks[] = ['check' => "fișier {$f}", 'ok' => is_file($rel.'/'.$f), 'detail' => ''];
    }
    $found = trim((string) @file_get_contents($rel.'/VERSION'));
    $checks[] = ['check' => 'versiune', 'ok' => $found === $version, 'detail' => "găsit „{$found}”"];
    try {
        $pdo->query('SELECT 1');
        $checks[] = ['check' => 'baza de date', 'ok' => true, 'detail' => ''];
        $ran = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = array_map(fn ($p) => basename($p, '.php'), glob($rel.'/database/migrations/*.php') ?: []);
        $pending = array_values(array_diff($files, $ran));
        $checks[] = ['check' => 'migrări', 'ok' => $files !== [] && $pending === [], 'detail' => $pending ? 'nerulate: '.implode(', ', array_slice($pending, 0, 5)) : 'toate rulate'];
    } catch (Throwable $e) {
        $checks[] = ['check' => 'baza de date', 'ok' => false, 'detail' => $e->getMessage()];
    }
    $checks[] = ['check' => 'stocare', 'ok' => is_dir($shared.'/storage/framework') && is_writable($shared.'/storage/framework'), 'detail' => ''];

    return $checks;
}

// ------------------------------------------------------------------ cerere

$https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (! $https && ! in_array($ip, ['127.0.0.1', '::1'], true) && getenv('SEVALIA_RECOVERY_ALLOW_HTTP') !== '1') {
    page('Conexiune nesigură', '<p>Deschideți adresa cu <strong>https://</strong>.</p>');
}
if (! is_dir($base.'/releases') || ! is_file($shared.'/keys/recovery.hash')) {
    page('SEVALIA nu a fost găsit', '<p>Nu găsesc directorul <code>sevalia</code> lângă <code>public_html</code> sau cheia de recuperare. Fișierul trebuie pus în <code>public_html</code>.</p>');
}

session_name('sevalia_repara');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$authed = isset($_SESSION['authed_at']) && time() - (int) $_SESSION['authed_at'] < SESSION_TTL;
$csrf = '<input type="hidden" name="csrf" value="'.$h($_SESSION['csrf']).'">';
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        page('Sesiune expirată', '<p>Reîncărcați pagina și reîncercați.</p>');
    }
    if (($_POST['action'] ?? '') === 'login') {
        try {
            if (checkKey($shared, (string) ($_POST['key'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['authed_at'] = time();
                $authed = true;
            } else {
                $message = 'Cheia de recuperare nu este corectă.';
            }
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
    }
}

if (! $authed) {
    page('Deblocarea actualizării SEVALIA', ($message ? '<p class="msg">'.$h($message).'</p>' : '')
        .'<p>Introduceți <strong>cheia de recuperare</strong> primită la instalare (aceeași ca pentru <code>recovery.php</code>).</p>'
        .'<form method="post">'.$csrf.'<input type="hidden" name="action" value="login"><label for="key">Cheie de recuperare</label>'
        .'<input id="key" name="key" type="password" autocomplete="off" required autofocus><button type="submit">Continuă</button></form>');
}

$env = readEnv($shared);
try {
    $pdo = db($env);
} catch (Throwable $e) {
    page('Baza de date nu răspunde', '<p class="err">Nu mă pot conecta la baza de date din <code>sevalia/shared/.env</code>: '.$h($e->getMessage()).'</p>');
}
$tz = new DateTimeZone($env['APP_TIMEZONE'] ?? 'Europe/Bucharest');
$now = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');

$row = $pdo->query("SELECT * FROM update_runs WHERE active_slot IS NOT NULL OR status = 'running' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pointer = pointer($base);
$current = (string) ($pointer['release'] ?? '');
$maintenance = is_file($shared.'/storage/framework/down');

if (! $row) {
    $body = '<p>Nu există nicio actualizare blocată. Versiunea activă: <strong>'.$h($current).'</strong>; mentenanță: <strong>'.($maintenance ? 'PORNITĂ' : 'oprită').'</strong>.</p>';
    if ($maintenance && ($_POST['action'] ?? '') === 'maintenance_off') {
        maintenanceOff($shared);
        logEvent($shared, 'repara_mentenanta_oprita');
        @unlink(__FILE__);
        page('Gata', '<p class="done">Mentenanța a fost oprită. Fișierul repara-actualizare.php a fost șters.</p>');
    }
    if ($maintenance) {
        $body .= '<form method="post">'.$csrf.'<input type="hidden" name="action" value="maintenance_off"><button type="submit">Oprește mentenanța</button></form>';
    } else {
        @unlink(__FILE__);
        $body .= '<p>Totul este în regulă; fișierul repara-actualizare.php a fost șters.</p>';
    }
    page('Deblocarea actualizării SEVALIA', $body);
}

$run = $row;
$run['steps'] = json_decode((string) $row['steps'], true) ?: [];
$to = (string) $run['to_version'];
$from = (string) $run['from_version'];
$migrated = ! empty($run['steps']['migrate']['data']['applied'] ?? []) || ! empty($run['steps']['migrate']['data']['current'] ?? null);
$previous = (string) ($run['steps']['activate']['data']['previous'] ?? $from);
$canFinalize = $current === $to && preg_match(RELEASE_PATTERN, $to) && is_file($base.'/releases/'.$to.'/vendor/autoload.php')
    && ($run['steps']['activate']['status'] ?? '') === 'done';
$canRollback = ! $migrated && preg_match(RELEASE_PATTERN, $previous) && is_file($base.'/releases/'.$previous.'/vendor/autoload.php');

$save = function (array $run) use ($pdo, $now): void {
    $st = $pdo->prepare('UPDATE update_runs SET status = ?, active_slot = NULL, current_step = NULL, steps = ?, error = ?, finished_at = ?, updated_at = ? WHERE id = ?');
    $st->execute([$run['status'], json_encode($run['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $run['error'], $now, $now, $run['id']]);
};

$action = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if ($action === 'finalize' && $canFinalize) {
    $checks = verifyRelease($base, $shared, $pdo, $to);
    $failed = array_filter($checks, fn ($c) => ! $c['ok']);
    if ($failed) {
        $message = 'Versiunea '.$to.' nu a trecut verificările: '.implode('; ', array_map(fn ($c) => $c['check'].($c['detail'] ? ' ('.$c['detail'].')' : ''), $failed)).'. Folosiți „Revino la versiunea anterioară” sau trimiteți această pagină echipei tehnice.';
    } else {
        $stamp = gmdate('c');
        foreach (['health', 'maintenance_off', 'cleanup'] as $step) {
            if (($run['steps'][$step]['status'] ?? '') !== 'done') {
                $run['steps'][$step] = ['status' => 'done', 'started_at' => $run['steps'][$step]['started_at'] ?? $stamp, 'finished_at' => $stamp,
                    'attempts' => $run['steps'][$step]['attempts'] ?? 0, 'data' => $step === 'health' ? ['results' => $checks, 'by' => 'repara-actualizare.php'] : ['by' => 'repara-actualizare.php']];
            }
        }
        $run['status'] = 'completed';
        $run['error'] = null;
        $run['finished_at'] = $now;
        $save($run);
        maintenanceOff($shared);
        removeDir($shared.'/updates/'.$run['uuid'].'/staging');
        @unlink($shared.'/updates/'.$run['uuid'].'/package.zip');
        journal($shared, $run, 'finalizat', ['prin' => 'repara-actualizare.php']);
        logEvent($shared, 'repara_finalizat', ['versiune' => $to]);
        @unlink(__FILE__);
        page('Actualizarea a fost finalizată', '<p class="done">Versiunea <strong>'.$h($to).'</strong> este activă și a trecut verificările; siteul a ieșit din mentenanță. '
            .'Fișierul repara-actualizare.php a fost șters.</p><p>Deschideți siteul și panoul. Puteți instala acum următoarea actualizare din Panou → Administrare → Actualizări.</p>');
    }
} elseif ($action === 'rollback' && $canRollback) {
    if ($current !== $previous) {
        writePointer($base, $previous, $current ?: null);
    }
    if (($run['steps']['activate']['status'] ?? '') === 'done') {
        $run['steps']['activate']['status'] = 'reverted';
    }
    $run['status'] = 'rolled_back';
    $run['error'] = trim((string) $run['error']."\nOprită cu repara-actualizare.php; baza de date nu fusese modificată; aplicația rulează versiunea {$previous}.");
    $run['finished_at'] = $now;
    $save($run);
    maintenanceOff($shared);
    journal($shared, $run, 'cod_revenit', ['versiune' => $previous, 'prin' => 'repara-actualizare.php']);
    logEvent($shared, 'repara_revenire', ['versiune' => $previous]);
    @unlink(__FILE__);
    page('Revenire făcută', '<p class="done">Aplicația rulează versiunea <strong>'.$h($previous).'</strong> și a ieșit din mentenanță. '
        .'Fișierul repara-actualizare.php a fost șters.</p><p>Instalați apoi pachetul nou din Panou → Administrare → Actualizări.</p>');
}

$labels = ['extract' => 'Despachetare și verificare', 'preflight' => 'Verificări preliminare', 'maintenance_on' => 'Mod mentenanță', 'backup_db' => 'Backup bază de date',
    'verify_backup' => 'Verificarea backupului', 'copy_release' => 'Copierea versiunii', 'link_shared' => 'Legarea datelor', 'migrate' => 'Migrări bază de date',
    'publish_assets' => 'Fișiere publice', 'activate' => 'Activarea versiunii', 'health' => 'Verificări de funcționare', 'maintenance_off' => 'Ieșire din mentenanță', 'cleanup' => 'Curățenie'];
$stateLabel = ['done' => '<span class="ok">încheiat</span>', 'running' => '<b>în curs</b>', 'failed' => '<span class="err">eșuat</span>', 'reverted' => 'anulat (revenire)'];
$rows = '';
foreach ($labels as $k => $l) {
    $s = $run['steps'][$k] ?? [];
    $rows .= '<tr><td>'.$h($l).'</td><td>'.($stateLabel[$s['status'] ?? ''] ?? 'în așteptare').'</td><td>'.$h(mb_substr((string) ($s['error'] ?? ''), 0, 200)).'</td></tr>';
}

$body = ($message ? '<p class="msg">'.$h($message).'</p>' : '')
    .'<p>Actualizare <strong>'.$h($from).' → '.$h($to).'</strong>, stare „'.$h($run['status']).'”. Versiunea activă acum: <strong>'.$h($current).'</strong>. '
    .'Mentenanță: <strong>'.($maintenance ? 'PORNITĂ (siteul arată eroarea 503)' : 'oprită').'</strong>.</p>'
    .'<table><tr><th>Pas</th><th>Stare</th><th>Eroare</th></tr>'.$rows.'</table>';
if ($canFinalize) {
    $body .= '<form method="post">'.$csrf.'<input type="hidden" name="action" value="finalize"><p><strong>Recomandat.</strong> Versiunea '.$h($to)
        .' este deja activă. Scriptul verifică fișierele, baza de date și migrările; dacă sunt în regulă, încheie actualizarea și oprește mentenanța.</p>'
        .'<button type="submit">Finalizează actualizarea '.$h($to).'</button></form>';
}
if ($canRollback) {
    $body .= '<form method="post">'.$csrf.'<input type="hidden" name="action" value="rollback"><p>Actualizarea nu a rulat migrări, deci baza de date este neatinsă. '
        .'Aplicația revine la versiunea '.$h($previous).' și iese din mentenanță.</p><button type="submit" class="sec">Revino la versiunea '.$h($previous).'</button></form>';
}
if (! $canFinalize && ! $canRollback) {
    $body .= '<p class="msg">Baza de date poate fi modificată de această actualizare, iar versiunea nouă nu este activă. Nu fac nimic automat: '
        .'deschideți Panou → Administrare → Actualizări (opțiunile de recuperare) sau trimiteți o captură a acestei pagini.</p>';
}
page('Deblocarea actualizării SEVALIA', $body);
