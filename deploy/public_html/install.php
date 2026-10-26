<?php

/*
 * SEVALIA — instalare din browser (cPanel fără Terminal).
 *
 * Pași: cod de instalare → verificarea serverului → configurare (bază de date, adresă, administrator) →
 * instalare (chei, migrări, date de bază, cont administrator) → importul conținutului siteului (pe bucăți scurte,
 * reluabil) → finalizare (cheia de recuperare afișată o singură dată, linia de cron, dezactivarea instalatorului).
 *
 * Protecție: înainte de orice pas, instalatorul cere codul scris în <deploy>/shared/install/cod-instalare.txt
 * (în afara public_html). Codul se citește din cPanel → File Manager, deci doar cine are acces la cont poate instala.
 * După finalizare instalatorul refuză orice acțiune și încearcă să se șteargă singur.
 */

declare(strict_types=1);

const SEVALIA_RELEASE_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';
const SEVALIA_MIN_PHP = '8.3.0';

$publicPath = __DIR__;
$deployPath = rtrim(getenv('SEVALIA_DEPLOY_PATH') ?: dirname(__DIR__).'/sevalia', '/');
$sharedPath = $deployPath.'/shared';
$installDir = $sharedPath.'/install';
$installedFlag = $sharedPath.'/.installed';
$https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

session_name('sevalia_instalare');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Cea mai nouă versiune completă din releases/. */
function sevalia_release(string $deployPath): ?string
{
    $best = null;
    foreach (glob($deployPath.'/releases/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (preg_match(SEVALIA_RELEASE_PATTERN, $name) && is_file($dir.'/vendor/autoload.php') && ($best === null || version_compare($name, $best, '>'))) {
            $best = $name;
        }
    }

    return $best;
}

function sevalia_state(string $installDir): array
{
    $s = json_decode((string) @file_get_contents($installDir.'/stare.json'), true);

    return is_array($s) ? $s : ['pas' => 'verificare'];
}

function sevalia_save_state(string $installDir, array $state): void
{
    file_put_contents($installDir.'/stare.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($installDir.'/stare.json', 0600);
}

/** Pornește consola aplicației din versiunea instalată, în același proces (fără exec/shell). */
function sevalia_kernel(string $releasePath, string $publicPath): \Illuminate\Contracts\Console\Kernel
{
    // Până la migrări, tabelele de cache/sesiuni nu există: instalatorul folosește fișiere.
    foreach (['CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync'] as $k => $v) {
        putenv("$k=$v");
        $_ENV[$k] = $_SERVER[$k] = $v;
    }
    require_once $releasePath.'/vendor/autoload.php';
    $app = require $releasePath.'/bootstrap/app.php';
    $app->usePublicPath($publicPath);
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    return $kernel;
}

function sevalia_env_value(string $v): string
{
    return preg_match('/^[A-Za-z0-9_.:\/@+\-]*$/', $v) ? $v : '"'.addcslashes($v, "\"\\\$").'"';
}

function sevalia_page(string $title, string $body, int $step, ?string $refresh = null): never
{
    $steps = ['Cod de instalare', 'Verificarea serverului', 'Configurare', 'Instalare', 'Importul conținutului', 'Finalizare'];
    $nav = '';
    foreach ($steps as $i => $label) {
        $cls = $i < $step ? 'done' : ($i === $step ? 'now' : '');
        $nav .= '<li class="'.$cls.'"><span>'.($i + 1).'</span>'.htmlspecialchars($label).'</li>';
    }
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .($refresh ? '<meta http-equiv="refresh" content="'.htmlspecialchars($refresh).'">' : '')
        .'<title>'.htmlspecialchars($title).' — Instalare SEVALIA</title><style>'
        .':root{--ink:#0e3b3d;--petrol:#006f75;--lagoon:#00c4c4;--mist:#eef6f6;--line:#d6e4e4;--ok:#2f7d4f;--warn:#9a5b00;--err:#b42318}'
        .'*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--mist);color:var(--ink);line-height:1.5}'
        .'header{background:var(--ink);color:#fff;padding:1rem 1.5rem;font-weight:700;font-size:1.15rem}'
        .'main{max-width:820px;margin:1.5rem auto;padding:0 1rem}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:1.5rem;margin-bottom:1rem}'
        .'ol.steps{display:flex;flex-wrap:wrap;gap:.4rem;list-style:none;padding:0;margin:0 0 1rem}ol.steps li{font-size:.8rem;padding:.3rem .6rem;border-radius:999px;background:#fff;border:1px solid var(--line);color:#6b8687}'
        .'ol.steps li span{font-weight:700;margin-right:.3rem}ol.steps li.now{border-color:var(--petrol);color:var(--ink);font-weight:600}ol.steps li.done{color:var(--ok)}'
        .'h1{font-size:1.5rem;margin:0 0 .75rem}label{display:block;font-weight:600;font-size:.9rem;margin-top:.9rem}'
        .'input{width:100%;padding:.55rem .7rem;border:1px solid #b9cfcf;border-radius:6px;font:inherit;margin-top:.25rem}'
        .'input:focus{outline:none;border-color:var(--petrol);box-shadow:0 0 0 3px rgba(0,196,196,.4)}'
        .'button,.btn{display:inline-block;margin-top:1.2rem;background:var(--petrol);color:#fff;border:0;border-radius:6px;padding:.65rem 1.2rem;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}'
        .'.hint{font-size:.82rem;color:#5b7778;margin:.2rem 0 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 1rem}@media(max-width:640px){.grid{grid-template-columns:1fr}}'
        .'table{width:100%;border-collapse:collapse;font-size:.9rem}td,th{text-align:left;padding:.45rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}'
        .'.ok{color:var(--ok);font-weight:700}.warn{color:var(--warn);font-weight:700}.err{color:var(--err);font-weight:700}'
        .'.alert{padding:.8rem 1rem;border-radius:8px;margin:.8rem 0}.alert.err{background:#fde8e6;color:var(--err);font-weight:400}.alert.warn{background:#fdf1dc;color:#6a4100;font-weight:400}.alert.ok{background:#e3f3e9;color:#1f5a37;font-weight:400}'
        .'code,pre{font-family:ui-monospace,Menlo,Consolas,monospace;background:var(--mist);border-radius:6px}code{padding:.1rem .35rem}pre{padding:.8rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all}'
        .'.key{font-size:1.25rem;font-weight:700;letter-spacing:.05em;padding:1rem;background:#fff7e0;border:2px dashed var(--warn);border-radius:8px;text-align:center}'
        .'progress{width:100%;height:1.2rem;accent-color:var(--petrol)}</style></head><body><header>SEVALIA — instalare</header><main>'
        .'<ol class="steps">'.$nav.'</ol><div class="card"><h1>'.htmlspecialchars($title).'</h1>'.$body.'</div></main></body></html>';
    exit;
}

// ---------------------------------------------------------------------------------------------- 0. deja instalat
if (is_file($installedFlag)) {
    @unlink(__FILE__);
    sevalia_page('Instalarea este deja finalizată', '<p>Aplicația este instalată. Acest instalator nu mai poate fi folosit.</p>'
        .(is_file(__FILE__) ? '<div class="alert warn">Ștergeți fișierul <code>public_html/install.php</code> din cPanel → File Manager.</div>' : '<p class="ok">Fișierul install.php a fost șters.</p>')
        .'<a class="btn" href="/panou/autentificare">Mergi la panou</a>', 5);
}

$release = sevalia_release($deployPath);
if ($release === null) {
    sevalia_page('Pachetul nu a fost găsit', '<p>Nu găsesc aplicația în <code>'.$h($deployPath).'/releases/&lt;versiune&gt;</code>.</p>'
        .'<p>Dezarhivați pachetul <code>sevalia-…-instalare-cpanel.zip</code> în directorul principal al contului (cel care conține <code>public_html</code>), '
        .'astfel încât să apară directorul <code>sevalia</code> lângă <code>public_html</code>.</p>', 0);
}
$releasePath = $deployPath.'/releases/'.$release;

foreach ([$sharedPath, $installDir] as $dir) {
    if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
        sevalia_page('Nu pot crea directoarele', '<p>Nu pot crea <code>'.$h($dir).'</code>. Verificați permisiunile directorului <code>sevalia</code> (0755) în File Manager.</p>', 0);
    }
}
@chmod($installDir, 0700);
if (! is_file($installDir.'/.htaccess')) {
    file_put_contents($installDir.'/.htaccess', "Require all denied\n");
}

// ---------------------------------------------------------------------------------------------- cod de instalare
$codeFile = $installDir.'/cod-instalare.txt';
if (! is_file($codeFile)) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    file_put_contents($codeFile, "Cod de instalare SEVALIA (de introdus în install.php):\n\n".$code."\n", LOCK_EX);
    @chmod($codeFile, 0600);
}
$expected = trim((string) preg_replace('/^.*\n\n/s', '', (string) file_get_contents($codeFile)));

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && ! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    sevalia_page('Sesiune expirată', '<p>Formularul a expirat. <a href="install.php">Reîncărcați pagina</a>.</p>', 0);
}
$csrf = '<input type="hidden" name="csrf" value="'.$h($_SESSION['csrf']).'">';

if (empty($_SESSION['autorizat'])) {
    $error = '';
    if ($post && isset($_POST['cod'])) {
        $attempts = (int) @file_get_contents($installDir.'/incercari.txt');
        if ($attempts >= 10) {
            $error = 'Prea multe încercări greșite. Ștergeți fișierul sevalia/shared/install/incercari.txt din File Manager pentru a reîncerca.';
        } elseif (hash_equals($expected, strtoupper(trim((string) $_POST['cod'])))) {
            session_regenerate_id(true);
            $_SESSION['autorizat'] = true;
            @unlink($installDir.'/incercari.txt');
            header('Location: install.php', true, 303);
            exit;
        } else {
            file_put_contents($installDir.'/incercari.txt', (string) ($attempts + 1));
            $error = 'Codul nu este corect.';
        }
    }
    sevalia_page('Cod de instalare', ($error ? '<div class="alert err">'.$h($error).'</div>' : '')
        .'<p>Pentru siguranță, instalarea cere un cod aflat într-un fișier pe care îl poate citi doar titularul contului de hosting.</p>'
        .'<ol><li>Deschideți cPanel → <strong>File Manager</strong>.</li><li>Intrați în <code>sevalia/shared/install/</code> (lângă <code>public_html</code>).</li>'
        .'<li>Deschideți fișierul <code>cod-instalare.txt</code> (clic dreapta → View) și copiați codul.</li></ol>'
        .'<form method="post">'.$csrf.'<label for="cod">Cod de instalare</label><input id="cod" name="cod" autocomplete="off" autofocus required><button type="submit">Continuă</button></form>'
        .'<p class="hint">Versiunea pachetului: '.$h($release).'</p>', 0);
}

$state = sevalia_state($installDir);

// ---------------------------------------------------------------------------------------------- 1. verificare
$checks = [];
$add = function (string $label, bool $ok, string $value, string $hint = '', bool $fatal = true) use (&$checks) {
    $checks[] = ['label' => $label, 'status' => $ok ? 'ok' : ($fatal ? 'err' : 'warn'), 'value' => $value, 'hint' => $hint];
};
$add('Versiunea PHP', version_compare(PHP_VERSION, SEVALIA_MIN_PHP, '>='), PHP_VERSION, 'Necesar ≥ 8.3 (cPanel → MultiPHP Manager).');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'sodium', 'zip', 'json', 'fileinfo', 'dom', 'xml', 'ctype', 'tokenizer', 'curl'] as $ext) {
    $add("Extensia $ext", extension_loaded($ext), extension_loaded($ext) ? 'da' : 'lipsește', 'cPanel → Select PHP Version / MultiPHP → extensii.');
}
$add('Extensia intl', extension_loaded('intl'), extension_loaded('intl') ? 'da' : 'lipsește', 'Recomandată (formatare în limba română).', false);
$add('GD cu WebP', function_exists('imagewebp'), function_exists('imagewebp') ? 'da' : 'nu', 'Necesar pentru imaginile optimizate ale siteului.');
$mem = ini_get('memory_limit');
$memBytes = $mem === '-1' ? PHP_INT_MAX : (int) $mem * (stripos((string) $mem, 'G') ? 1073741824 : (stripos((string) $mem, 'M') ? 1048576 : 1));
$add('memory_limit', $memBytes >= 256 * 1048576, (string) $mem, 'Recomandat ≥ 256M (MultiPHP INI Editor).', false);
$upload = ini_get('upload_max_filesize');
$add('upload_max_filesize', true, (string) $upload, 'Pentru actualizările din panou: ≥ 64M (MultiPHP INI Editor → upload_max_filesize și post_max_size).', false);
$free = @disk_free_space($deployPath);
$add('Spațiu liber', $free === false || $free > 1024 * 1048576, $free === false ? 'necunoscut' : round($free / 1073741824, 1).' GB', 'Necesar ≥ 1 GB.', false);
$add('Scriere în sevalia/', is_writable($deployPath), is_writable($deployPath) ? 'da' : 'nu', 'Permisiuni 0755 pentru directorul sevalia.');
$add('Scriere în public_html/', is_writable($publicPath), is_writable($publicPath) ? 'da' : 'nu', 'Permisiuni 0755 pentru public_html.');
$add('HTTPS', $https, $https ? 'da' : 'nu', 'Activați AutoSSL (cPanel → SSL/TLS Status) și deschideți install.php prin https://', false);
$blocking = array_filter($checks, fn ($c) => $c['status'] === 'err');

if (($state['pas'] ?? 'verificare') === 'verificare') {
    if ($post && isset($_POST['continua']) && ! $blocking) {
        $state['pas'] = 'configurare';
        sevalia_save_state($installDir, $state);
        header('Location: install.php', true, 303);
        exit;
    }
    $rows = '';
    foreach ($checks as $c) {
        $rows .= '<tr><td>'.$h($c['label']).'</td><td>'.$h($c['value']).'</td><td class="'.$c['status'].'">'
            .['ok' => 'ok', 'warn' => 'atenție', 'err' => 'EROARE'][$c['status']].'</td><td class="hint">'.($c['status'] === 'ok' ? '' : $h($c['hint'])).'</td></tr>';
    }
    sevalia_page('Verificarea serverului', '<p>Versiunea de instalat: <strong>'.$h($release).'</strong>.</p>'
        .'<table><tr><th>Element</th><th>Valoare</th><th>Stare</th><th>Ce faceți</th></tr>'.$rows.'</table>'
        .($blocking ? '<div class="alert err">Rezolvați elementele marcate EROARE (din cPanel), apoi <a href="install.php">reîncărcați pagina</a>.</div>'
            : '<form method="post">'.$csrf.'<button name="continua" value="1">Continuă</button></form>'), 1);
}

// ---------------------------------------------------------------------------------------------- 2. configurare
if ($state['pas'] === 'configurare') {
    $v = fn (string $k, string $d = '') => $h($_POST[$k] ?? $d);
    $errors = [];
    if ($post && isset($_POST['salveaza'])) {
        $in = array_map(fn ($x) => is_string($x) ? trim($x) : '', $_POST);
        $url = rtrim($in['url'] ?? '', '/');
        if (! preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#i', $url)) {
            $errors[] = 'Adresa siteului trebuie să fie de forma https://www.sevalia.ro (fără cale la final).';
        }
        foreach (['db_host' => 'Serverul bazei de date', 'db_name' => 'Numele bazei de date', 'db_user' => 'Utilizatorul bazei de date', 'admin_name' => 'Numele administratorului'] as $k => $label) {
            if (($in[$k] ?? '') === '') {
                $errors[] = "$label este obligatoriu.";
            }
        }
        if (! filter_var($in['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresa de e-mail a administratorului nu este validă.';
        }
        $pass = (string) ($_POST['admin_password'] ?? '');
        if (mb_strlen($pass) < 12 || ! preg_match('/\d/', $pass) || ! preg_match('/\pL/u', $pass)) {
            $errors[] = 'Parola administratorului: minimum 12 caractere, cu litere și cifre.';
        } elseif ($pass !== (string) ($_POST['admin_password_confirmation'] ?? '')) {
            $errors[] = 'Parolele administratorului nu coincid.';
        }
        if (! $errors) {
            try {
                $port = (int) ($in['db_port'] ?: 3306);
                $pdo = new PDO("mysql:host={$in['db_host']};port={$port};dbname={$in['db_name']};charset=utf8mb4", $in['db_user'], (string) ($_POST['db_password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                $isMaria = stripos($version, 'mariadb') !== false;
                $num = preg_replace('/[^0-9.].*$/', '', $version);
                if (($isMaria && version_compare($num, '10.6', '<')) || (! $isMaria && version_compare($num, '8.0', '<'))) {
                    $errors[] = "Versiunea bazei de date ($version) este prea veche: necesar MariaDB ≥ 10.6 sau MySQL ≥ 8.0.";
                }
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                if ($tables) {
                    $errors[] = 'Baza de date nu este goală ('.count($tables).' tabele). Pentru protecția datelor, instalarea se face doar într-o bază nouă, goală.';
                }
            } catch (PDOException $e) {
                $errors[] = 'Nu mă pot conecta la baza de date: verificați numele bazei, utilizatorul și parola (cPanel → MySQL Databases; utilizatorul trebuie adăugat la bază cu ALL PRIVILEGES).';
            }
        }
        if (! $errors) {
            $env = (string) file_get_contents($releasePath.'/.env.example');
            $set = function (string $key, string $value) use (&$env) {
                $line = $key.'='.sevalia_env_value($value);
                $env = preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env) ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env) : $env."\n".$line;
            };
            $values = [
                'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => $url,
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'SEVALIA_BLIND_INDEX_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'SEVALIA_STAGE' => 'production',
                'SEVALIA_DEPLOY_PATH' => $deployPath, 'SEVALIA_PUBLIC_PATH' => $publicPath, 'SEVALIA_SHARED_PATH' => $sharedPath,
                'SEVALIA_MEDIA_PATH' => $publicPath.'/media',
                'DB_CONNECTION' => 'mysql', 'DB_HOST' => $in['db_host'], 'DB_PORT' => (string) ($in['db_port'] ?: 3306),
                'DB_DATABASE' => $in['db_name'], 'DB_USERNAME' => $in['db_user'], 'DB_PASSWORD' => (string) ($_POST['db_password'] ?? ''),
                'SESSION_SECURE_COOKIE' => str_starts_with($url, 'https://') ? 'true' : 'false',
                'LOG_CHANNEL' => 'daily', 'LOG_LEVEL' => 'warning',
            ];
            if (($in['mail_host'] ?? '') !== '') {
                $values += ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => $in['mail_host'], 'MAIL_PORT' => (string) ($in['mail_port'] ?: 465),
                    'MAIL_SCHEME' => ((int) ($in['mail_port'] ?: 465)) === 465 ? 'smtps' : 'smtp', 'MAIL_USERNAME' => $in['mail_user'] ?? '',
                    'MAIL_PASSWORD' => (string) ($_POST['mail_password'] ?? ''), 'MAIL_FROM_ADDRESS' => $in['mail_from'] ?: ($in['mail_user'] ?? '')];
            }
            foreach ($values as $k => $val) {
                $set($k, $val);
            }
            file_put_contents($sharedPath.'/.env', $env, LOCK_EX);
            @chmod($sharedPath.'/.env', 0600);
            foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'backups', 'updates', 'keys'] as $d) {
                @mkdir($sharedPath.'/'.$d, $d === 'keys' ? 0700 : 0755, true);
            }
            file_put_contents($releasePath.'/.shared-path', $sharedPath);
            $_SESSION['admin'] = ['name' => $in['admin_name'], 'email' => strtolower($in['admin_email']), 'password' => $pass];
            $state['pas'] = 'instalare';
            $state['url'] = $url;
            sevalia_save_state($installDir, $state);
            header('Location: install.php', true, 303);
            exit;
        }
    }
    $default = ($https ? 'https://' : 'http://').preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'www.sevalia.ro'));
    sevalia_page('Configurare', ($errors ? '<div class="alert err"><ul><li>'.implode('</li><li>', array_map($h, $errors)).'</li></ul></div>' : '')
        .'<form method="post" autocomplete="off">'.$csrf
        .'<h2>Site</h2><label for="url">Adresa siteului</label><input id="url" name="url" value="'.$v('url', $default).'" required><p class="hint">Exact cum vor accesa vizitatorii siteul, ex. https://www.sevalia.ro</p>'
        .'<h2>Baza de date</h2><p class="hint">Creați-o înainte în cPanel → <strong>MySQL Database Wizard</strong>: o bază nouă, un utilizator, ALL PRIVILEGES.</p>'
        .'<div class="grid"><div><label for="db_host">Server</label><input id="db_host" name="db_host" value="'.$v('db_host', 'localhost').'" required></div>'
        .'<div><label for="db_port">Port</label><input id="db_port" name="db_port" value="'.$v('db_port', '3306').'"></div>'
        .'<div><label for="db_name">Numele bazei</label><input id="db_name" name="db_name" value="'.$v('db_name').'" required placeholder="cont_sevalia"></div>'
        .'<div><label for="db_user">Utilizator</label><input id="db_user" name="db_user" value="'.$v('db_user').'" required placeholder="cont_sevalia"></div></div>'
        .'<label for="db_password">Parola utilizatorului bazei</label><input id="db_password" name="db_password" type="password">'
        .'<h2>Administratorul tehnic</h2><p class="hint">Primul cont al panoului. La prima autentificare se configurează autentificarea în doi pași (aplicație pe telefon).</p>'
        .'<div class="grid"><div><label for="admin_name">Nume</label><input id="admin_name" name="admin_name" value="'.$v('admin_name').'" required></div>'
        .'<div><label for="admin_email">E-mail</label><input id="admin_email" name="admin_email" type="email" value="'.$v('admin_email').'" required></div>'
        .'<div><label for="admin_password">Parolă</label><input id="admin_password" name="admin_password" type="password" required minlength="12"></div>'
        .'<div><label for="admin_password_confirmation">Repetați parola</label><input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required></div></div>'
        .'<p class="hint">Minimum 12 caractere, cu litere și cifre.</p>'
        .'<h2>E-mail (opțional)</h2><p class="hint">Pentru resetarea parolei și notificări. Creați căsuța în cPanel → Email Accounts; datele le găsiți la „Connect Devices”. Se pot completa și mai târziu.</p>'
        .'<div class="grid"><div><label for="mail_host">Server SMTP</label><input id="mail_host" name="mail_host" value="'.$v('mail_host').'" placeholder="mail.sevalia.ro"></div>'
        .'<div><label for="mail_port">Port</label><input id="mail_port" name="mail_port" value="'.$v('mail_port', '465').'"></div>'
        .'<div><label for="mail_user">Utilizator (adresa)</label><input id="mail_user" name="mail_user" value="'.$v('mail_user').'"></div>'
        .'<div><label for="mail_password">Parola căsuței</label><input id="mail_password" name="mail_password" type="password"></div>'
        .'<div><label for="mail_from">Adresa expeditorului</label><input id="mail_from" name="mail_from" value="'.$v('mail_from').'" placeholder="notificari@sevalia.ro"></div></div>'
        .'<button name="salveaza" value="1">Salvează și continuă</button></form>', 2);
}

// ---------------------------------------------------------------------------------------------- 3. instalare
if ($state['pas'] === 'instalare') {
    if (empty($_SESSION['admin'])) {
        $state['pas'] = 'configurare';
        sevalia_save_state($installDir, $state);
        sevalia_page('Sesiune expirată', '<p>Datele administratorului nu mai sunt în sesiune. <a href="install.php">Completați din nou configurarea</a>.</p>', 2);
    }
    if (! $post) {
        sevalia_page('Instalare', '<p>Se vor crea tabelele bazei de date, cheile de securitate, datele de bază ale clinicii și contul administratorului. Durează de obicei sub un minut.</p>'
            .'<form method="post">'.$csrf.'<button name="instaleaza" value="1">Instalează</button></form>', 3);
    }
    @set_time_limit(300);
    $out = '';
    try {
        $kernel = sevalia_kernel($releasePath, $publicPath);
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $code = $kernel->call('sevalia:install', ['--force' => true, '--no-interaction' => true], $buffer);
        $out = $buffer->fetch();
        if ($code !== 0) {
            throw new RuntimeException('Comanda de instalare a raportat o eroare.');
        }
        if (preg_match('/CHEIA DE RECUPERARE[^\n]*\n\s*(\S+)/u', $out, $m)) {
            $_SESSION['cheie_recuperare'] = $m[1];
        }
        $admin = $_SESSION['admin'];
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $code = $kernel->call('sevalia:create-admin', ['email' => $admin['email'], '--name' => $admin['name'], '--password' => $admin['password'], '--no-interaction' => true], $buffer);
        $out .= "\n".$buffer->fetch();
        if ($code !== 0 && ! str_contains($out, 'Există deja un cont')) {
            throw new RuntimeException('Contul administratorului nu a putut fi creat.');
        }
        unset($_SESSION['admin']);
        $state['pas'] = 'import';
        sevalia_save_state($installDir, $state);
    } catch (Throwable $e) {
        $safe = preg_replace('/(PASSWORD|password|parol[aă])[^\n]*/u', '$1 [ascuns]', $out);
        sevalia_page('Instalarea a întâmpinat o problemă', '<div class="alert err">'.$h($e->getMessage()).'</div>'
            .'<p>Detalii tehnice (fără parole):</p><pre>'.$h(mb_substr($safe, -6000)).'</pre>'
            .'<p class="hint">'.$h(get_class($e).': '.mb_substr($e->getMessage(), 0, 500)).'</p>'
            .'<form method="post">'.$csrf.'<button name="instaleaza" value="1">Reîncearcă</button></form>', 3);
    }
    header('Location: install.php', true, 303);
    exit;
}

// ---------------------------------------------------------------------------------------------- 4. import conținut
if ($state['pas'] === 'import') {
    $limit = (int) ini_get('max_execution_time');
    $budget = $limit > 0 ? max(5, min(20, $limit - 10)) : 20;
    @set_time_limit($budget + 60);
    try {
        $kernel = sevalia_kernel($releasePath, $publicPath);
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $code = $kernel->call('sevalia:import-site', ['--buget' => $budget], $buffer);
        $out = $buffer->fetch();
    } catch (Throwable $e) {
        sevalia_page('Importul conținutului s-a oprit', '<div class="alert err">'.$h(get_class($e).': '.mb_substr($e->getMessage(), 0, 500)).'</div>'
            .'<p>Importul se poate relua fără pierderi.</p><a class="btn" href="install.php">Reia importul</a>', 4);
    }
    if ($code === 3 && preg_match('/INCOMPLET (\d+)\/(\d+)/', $out, $m)) {
        $pct = (int) round(100 * (int) $m[1] / max(1, (int) $m[2]));
        sevalia_page('Importul conținutului siteului', '<p>Se preiau paginile, textele, serviciile, articolele și fotografiile siteului (din arhiva inclusă în pachet) și se generează imaginile optimizate.</p>'
            .'<progress value="'.$pct.'" max="100"></progress><p>'.$h($m[1]).' din '.$h($m[2]).' resurse ('.$pct.'%). Pagina continuă automat; nu o închideți.</p>'
            .'<p class="hint">Dacă pagina nu continuă singură în câteva secunde, <a href="install.php">apăsați aici</a>.</p>', 4, '1;url=install.php');
    }
    if ($code !== 0) {
        sevalia_page('Importul conținutului a raportat o problemă', '<pre>'.$h(mb_substr($out, -6000)).'</pre><a class="btn" href="install.php">Reia importul</a>', 4);
    }
    $state['pas'] = 'final';
    $state['raport_import'] = mb_substr($out, -4000);
    sevalia_save_state($installDir, $state);
    header('Location: install.php', true, 303);
    exit;
}

// ---------------------------------------------------------------------------------------------- 5. finalizare
if ($state['pas'] === 'final') {
    $key = $_SESSION['cheie_recuperare'] ?? null;
    $phpCli = '/usr/local/bin/php';
    if (preg_match('/^(\d+)\.(\d+)/', PHP_VERSION, $vm) && is_file("/opt/cpanel/ea-php{$vm[1]}{$vm[2]}/root/usr/bin/php")) {
        $phpCli = "/opt/cpanel/ea-php{$vm[1]}{$vm[2]}/root/usr/bin/php";
    }
    $cron = $phpCli.' '.$deployPath.'/artisan schedule:run >> /dev/null 2>&1';
    if ($post && isset($_POST['finalizeaza'])) {
        file_put_contents($installedFlag, json_encode(['instalat_la' => date('c'), 'versiune' => $release], JSON_PRETTY_PRINT));
        @unlink($codeFile);
        unset($_SESSION['cheie_recuperare']);
        session_destroy();
        $deleted = @unlink(__FILE__);
        sevalia_page('Instalare finalizată', '<div class="alert ok">Siteul și panoul sunt instalate.</div>'
            .($deleted ? '<p>Instalatorul a fost șters automat.</p>' : '<div class="alert warn">Ștergeți acum fișierul <code>public_html/install.php</code> din File Manager.</div>')
            .'<p><a class="btn" href="/">Deschide siteul</a> <a class="btn" href="/panou/autentificare">Deschide panoul</a></p>', 5);
    }
    sevalia_page('Ultimii pași', '<div class="alert ok">Instalarea și importul conținutului s-au încheiat.</div>'
        .($key ? '<h2>1. Cheia de recuperare</h2><p>Notați-o acum și păstrați-o offline (ex. pe hârtie, în seif). Se folosește în <code>'.$h(parse_url($state['url'] ?? '', PHP_URL_HOST) ?: 'domeniu').'/recovery.php</code> dacă, după o actualizare, aplicația nu mai pornește. <strong>Nu va mai fi afișată.</strong></p><div class="key">'.$h($key).'</div>'
            : '<div class="alert warn">Cheia de recuperare a fost deja afișată într-o sesiune anterioară.</div>')
        .'<h2>2. Sarcina programată (cron)</h2><p>cPanel → <strong>Cron Jobs</strong> → „Common Settings”: <em>Once Per Minute (* * * * *)</em>; în câmpul „Command” lipiți:</p><pre>'.$h($cron).'</pre>'
        .'<p class="hint">Asigură trimiterea notificărilor, backupurile automate și întreținerea. Dacă PHP-ul contului are altă cale, cPanel o arată în MultiPHP Manager.</p>'
        .'<h2>3. Copii de siguranță offline</h2><p>Descărcați din File Manager și păstrați offline: <code>sevalia/shared/.env</code> și directorul <code>sevalia/shared/keys/</code>. Fără ele backupurile nu pot fi citite.</p>'
        .'<h2>4. Prima autentificare</h2><p>La prima intrare în panou veți configura autentificarea în doi pași cu o aplicație pe telefon (Microsoft Authenticator, Google Authenticator, 2FAS).</p>'
        .'<form method="post">'.$csrf.'<button name="finalizeaza" value="1">Am notat cheia — finalizează și dezactivează instalatorul</button></form>', 5);
}

sevalia_page('Stare necunoscută', '<p>Starea instalării nu este recunoscută. Ștergeți <code>sevalia/shared/install/stare.json</code> și reîncărcați.</p>', 0);
