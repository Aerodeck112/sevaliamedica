<?php

/*
 * SEVALIA — front controller pentru public_html (cPanel).
 *
 * Citește versiunea activă din <deploy>/current.json și pornește aplicația din <deploy>/releases/<versiune>.
 * Nu necesită legături simbolice. Fișierul este administrat de actualizări: nu îl modificați manual.
 *
 * <deploy> = variabila de mediu SEVALIA_DEPLOY_PATH sau, implicit, directorul „sevalia” de lângă public_html
 * (ex. /home/<cont>/sevalia când public_html este /home/<cont>/public_html).
 */

define('LARAVEL_START', microtime(true));

$sevaliaBase = getenv('SEVALIA_DEPLOY_PATH') ?: dirname(__DIR__).'/sevalia';

$sevaliaPointer = json_decode((string) @file_get_contents($sevaliaBase.'/current.json'), true);
$sevaliaRelease = is_array($sevaliaPointer) ? (string) ($sevaliaPointer['release'] ?? '') : '';
$sevaliaApp = $sevaliaBase.'/releases/'.$sevaliaRelease;

if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $sevaliaRelease) || ! is_file($sevaliaApp.'/vendor/autoload.php')) {
    http_response_code(503);
    header('Retry-After: 300');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ro"><meta charset="utf-8"><title>Indisponibil temporar</title>'
        .'<body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#0e3b3d">'
        .'<h1>Siteul este indisponibil temporar</h1><p>Vă rugăm reveniți în câteva minute. Pentru programări: 0365 403 001.</p>'
        .'</body></html>';
    exit;
}

// Mentenanță: răspuns rapid, înainte de încărcarea aplicației (fișier generat de Laravel, în storage partajat).
if (is_file($sevaliaMaintenance = $sevaliaBase.'/shared/storage/framework/maintenance.php')) {
    require $sevaliaMaintenance;
}

require $sevaliaApp.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $sevaliaApp.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(\Illuminate\Http\Request::capture());
