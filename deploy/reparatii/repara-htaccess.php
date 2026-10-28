<?php

/*
 * SEVALIA — reparare unică a fișierului public_html/.htaccess (fără Terminal).
 *
 * Problema: la instalare exista deja un .htaccess creat de cPanel, iar instalatorul 0.9.1 l-a păstrat; fără regulile
 * SEVALIA serverul nu trimite adresele către aplicație și toate paginile, în afară de prima, dau 404.
 *
 * Utilizare: cPanel → File Manager → public_html → Upload acest fișier → deschideți
 * https://<domeniu>/repara-htaccess.php → „Repară”. Scriptul păstrează blocurile cPanel (versiunea PHP, setări
 * php.ini), salvează fișierul vechi ca .htaccess.inainte-de-sevalia-<dată> și se șterge singur.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

const MARKER = 'SEVALIA-MANAGED';
$template = <<<'SEVALIA_HTACCESS'
# SEVALIA-MANAGED — fișier administrat de instalare și de actualizări. Se păstrează la fiecare actualizare:
# blocurile scrise de cPanel (versiunea PHP, setări php.ini) și regulile puse între cele două linii de mai jos.

# BEGIN reguli proprii
# (reguli proprii ale clinicii, ex. redirecționări; rulează înaintea regulilor SEVALIA)
# END reguli proprii

Options -Indexes -MultiViews
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On

    # HTTPS obligatoriu (certificatul AutoSSL/Let's Encrypt din cPanel).
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP:X-Forwarded-Proto} !=https
    RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1)
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Antetul Authorization ajunge la PHP.
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Fișiere ascunse, copii de rezervă și fișiere de configurare: niciodată servite.
    RewriteRule (^|/)\.(?!well-known/) - [F,L]
    RewriteRule \.(env|log|sql|sbk|bak|key|hash|ini|sh|zip|lock)$ - [F,NC,L]

    # Restul cererilor către front controller (fișierele existente sunt servite direct).
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" "expr=%{HTTPS} == 'on'"

    # Asseturile compilate au nume cu hash: se pot păstra un an.
    <FilesMatch "\.(css|js|woff2?)$">
        Header set Cache-Control "public, max-age=31536000, immutable" "expr=%{REQUEST_URI} =~ m#^/build/#"
    </FilesMatch>
    <FilesMatch "\.(avif|webp|jpe?g|png|gif|svg|ico)$">
        Header set Cache-Control "public, max-age=2592000"
    </FilesMatch>
    <Files "recovery.php">
        Header always set Cache-Control "no-store"
        Header always set X-Robots-Tag "noindex, nofollow"
    </Files>
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml text/xml application/xml
</IfModule>
SEVALIA_HTACCESS;

$cpanelPatterns = [
    '/^# php -- BEGIN cPanel-generated handler.*?^# php -- END cPanel-generated handler[^\n]*\n?/ms',
    '/^# BEGIN cPanel-generated php ini directives.*?^# END cPanel-generated php ini directives[^\n]*\n?/ms',
];
$customPattern = '/^# BEGIN reguli proprii.*?^# END reguli proprii[^\n]*\n?/ms';

function sevalia_merge(string $template, ?string $existing, array $cpanelPatterns, string $customPattern): string
{
    $custom = preg_match($customPattern, (string) $existing, $m) ? $m[0] : (preg_match($customPattern, $template, $m) ? $m[0] : null);
    $cpanel = [];
    foreach ($cpanelPatterns as $re) {
        if ($existing !== null && preg_match_all($re, $existing, $mm)) {
            foreach ($mm[0] as $block) {
                $cpanel[] = rtrim($block)."\n";
            }
        }
    }
    $body = $template;
    foreach ([...$cpanelPatterns, $customPattern] as $re) {
        $body = (string) preg_replace($re, '', $body);
    }
    $body = rtrim($body)."\n";
    $header = '';
    if (preg_match('/\A(?:#[^\n]*\n)+/', $body, $m)) {
        $header = $m[0];
        $body = ltrim(substr($body, strlen($header)), "\n");
    }

    return $header.($custom !== null ? "\n".rtrim($custom)."\n" : '')."\n".$body.($cpanel ? "\n".implode("\n", $cpanel) : '');
}

function sevalia_page(string $title, string $body): never
{
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>'.htmlspecialchars($title).' — SEVALIA</title><style>body{font-family:system-ui,sans-serif;background:#eef6f6;color:#0e3b3d;margin:0}'
        .'header{background:#0e3b3d;color:#fff;padding:1rem 1.5rem;font-weight:700}main{max-width:46rem;margin:2rem auto;padding:0 1rem}'
        .'.card{background:#fff;border:1px solid #d6e4e4;border-radius:12px;padding:1.5rem}.ok{background:#e3f3e9;color:#1f5a37;padding:.8rem 1rem;border-radius:8px}'
        .'.err{background:#fde8e6;color:#b42318;padding:.8rem 1rem;border-radius:8px}button{background:#006f75;color:#fff;border:0;border-radius:8px;padding:.8rem 1.4rem;font-size:1rem;font-weight:700;cursor:pointer}'
        .'code{background:#eef6f6;padding:.1rem .35rem;border-radius:5px}li{margin:.3rem 0}</style></head><body><header>SEVALIA — reparare .htaccess</header>'
        .'<main><div class="card"><h1>'.htmlspecialchars($title).'</h1>'.$body.'</div></main></body></html>';
    exit;
}

$public = __DIR__;
$target = $public.'/.htaccess';
if (! is_file(dirname(__DIR__).'/sevalia/current.json') || ! is_file($public.'/index.php')) {
    sevalia_page('Locație greșită', '<p class="err">Acest fișier trebuie pus în <code>public_html</code>-ul siteului SEVALIA (lângă <code>index.php</code>, cu directorul <code>sevalia</code> alături de <code>public_html</code>).</p>');
}
$existing = is_file($target) ? (string) file_get_contents($target) : null;
$merged = sevalia_merge($template, $existing, $cpanelPatterns, $customPattern);
$host = htmlspecialchars((string) ($_SERVER['HTTP_HOST'] ?? 'domeniu'));
$tests = '<ul><li><a href="/kinetoterapie" target="_blank">/kinetoterapie</a></li><li><a href="/programare-online" target="_blank">/programare-online</a></li>'
    .'<li><a href="/panou/autentificare" target="_blank">/panou/autentificare</a> (panoul)</li><li><a href="/robots.txt" target="_blank">/robots.txt</a></li></ul>';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $kept = [];
    foreach ($cpanelPatterns as $re) {
        if ($existing !== null && preg_match($re, $existing)) {
            $kept[] = str_contains($re, 'handler') ? 'versiunea PHP aleasă în MultiPHP Manager' : 'setările din MultiPHP INI Editor';
        }
    }
    sevalia_page('Reparare .htaccess', '<p>Fișierul <code>public_html/.htaccess</code> '.($existing === null ? 'lipsește' : (str_contains($existing, MARKER) ? 'este al SEVALIA, dar poate fi actualizat' : 'nu conține regulile SEVALIA'))
        .' — de aceea paginile siteului (în afară de prima) răspund „404 Not found”.</p>'
        .'<p>Reparația scrie regulile SEVALIA'.($kept ? ' și păstrează: <strong>'.htmlspecialchars(implode(', ', $kept)).'</strong>' : '').'. Fișierul vechi se salvează ca <code>.htaccess.inainte-de-sevalia-'.date('Ymd').'-…</code>. Apoi acest fișier se șterge singur.</p>'
        .'<form method="post"><button type="submit">Repară</button></form>');
}

if ($existing !== null && $existing !== $merged && ! @copy($target, $target.'.inainte-de-sevalia-'.date('Ymd-His'))) {
    sevalia_page('Nu s-a putut face copia', '<p class="err">Nu am putut salva copia fișierului existent; nu am modificat nimic. Verificați permisiunile directorului <code>public_html</code> (0755).</p>');
}
$tmp = $target.'.tmp-'.bin2hex(random_bytes(3));
if ($existing !== $merged && (file_put_contents($tmp, $merged) === false || ! rename($tmp, $target))) {
    @unlink($tmp);
    sevalia_page('Scrierea a eșuat', '<p class="err">Nu am putut scrie <code>.htaccess</code>. Verificați permisiunile directorului <code>public_html</code> (0755).</p>');
}
@chmod($target, 0644);
$deleted = @unlink(__FILE__);
sevalia_page('Reparat', '<p class="ok">Regulile SEVALIA sunt acum în <code>public_html/.htaccess</code>.</p><p>Verificați (fiecare trebuie să se deschidă):</p>'.$tests
    .($deleted ? '<p>Acest fișier s-a șters singur.</p>' : '<p class="err">Ștergeți acum din File Manager fișierul <code>public_html/repara-htaccess.php</code>.</p>'));
