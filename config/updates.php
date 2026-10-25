<?php

/*
 * Actualizări prin pachete ZIP semnate și backup.
 *
 * Structura pe hosting (vezi docs/ARHITECTURA.md §4):
 *   <deploy_path>/releases/<versiune>/   codul fiecărei versiuni
 *   <deploy_path>/shared/                .env, storage, backups, updates, keys
 *   <deploy_path>/current.json           versiunea activă
 *   <public_path>/                       public_html (front controller, build/, recovery.php)
 *
 * Fără deploy_path aplicația rulează „în loc” (dezvoltare): pachetele pot fi verificate, dar nu instalate.
 */

$deploy = env('SEVALIA_DEPLOY_PATH');
$shared = env('SEVALIA_SHARED_PATH') ?: ($deploy ? rtrim($deploy, '/').'/shared' : storage_path('app/sevalia-shared'));

return [
    'deploy_path' => $deploy ? rtrim($deploy, '/') : null,
    'public_path' => env('SEVALIA_PUBLIC_PATH') ? rtrim(env('SEVALIA_PUBLIC_PATH'), '/') : null,
    'shared_path' => rtrim($shared, '/'),

    // Conexiunea bazei de date care se salvează și se migrează (null = conexiunea implicită).
    'connection' => env('SEVALIA_UPDATE_DB_CONNECTION'),

    // Cheile publice Ed25519 de încredere (fișiere *.pub, base64). Se pot adăuga directoare suplimentare
    // (ex. pentru staging) prin SEVALIA_UPDATE_KEYS_EXTRA, separate prin „:”.
    'trusted_keys_paths' => array_values(array_filter([
        base_path('config/update-keys'),
        ...explode(':', (string) env('SEVALIA_UPDATE_KEYS_EXTRA', '')),
    ])),

    // Limite de siguranță pentru pachete.
    'limits' => [
        'max_package_bytes' => 300 * 1024 * 1024,
        'max_entries' => 80000,
        'max_uncompressed_bytes' => 900 * 1024 * 1024,
        'max_entry_bytes' => 150 * 1024 * 1024,
        'max_compression_ratio' => 200,
        // Spațiu liber necesar: mărimea dezarhivată × factor (release nou + staging + backup).
        'free_space_factor' => 3,
    ],

    // Directoarele și fișierele permise în payload/. Orice altă cale este respinsă.
    'payload_roots' => [
        'app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor', 'deploy',
        'artisan', 'composer.json', 'composer.lock', 'VERSION', 'CHANGELOG.md',
    ],

    // Extensii PHP cerute de aplicație (un pachet poate cere și altele în manifest).
    'required_extensions' => ['pdo_mysql', 'mbstring', 'openssl', 'sodium', 'zip', 'json', 'fileinfo', 'dom', 'xml', 'ctype', 'tokenizer'],

    // Timp maxim per pas executat dintr-o cerere web (secunde). Pașii mari continuă în cererea următoare.
    'step_time_budget' => (int) env('SEVALIA_UPDATE_STEP_BUDGET', 15),

    // Plafon pentru spațiul liber raportat (octeți), când cota contului cPanel e mai mică decât discul.
    'disk_free_cap' => (int) env('SEVALIA_DISK_FREE_CAP', 0),

    // Câte versiuni anterioare se păstrează în releases/ pentru revenire.
    'keep_releases' => 3,

    // Health check HTTP după activare (opțional; pe unele hostinguri cererile loopback sunt blocate).
    'health_url' => env('SEVALIA_HEALTH_URL'),

    'backup' => [
        // Câte backupuri reușite se păstrează, pe tip.
        'retain' => [
            'database' => (int) env('SEVALIA_BACKUP_RETAIN_DB', 14),
            'documents' => (int) env('SEVALIA_BACKUP_RETAIN_DOCS', 8),
        ],
        // Tabele salvate doar ca structură (date tranzitorii).
        'structure_only' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'],
        'rows_per_insert' => 200,
        'rows_per_chunk' => 2000,
        // Directoarele cu documente private incluse în backupul de documente (relative la storage).
        'documents_dirs' => ['app/private'],
        // Ora backupului zilnic al bazei de date (cron).
        'daily_at' => env('SEVALIA_BACKUP_AT', '02:30'),
    ],
];
