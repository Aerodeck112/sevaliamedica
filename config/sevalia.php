<?php

return [
    // Versiunea aplicației; sursa de adevăr pentru actualizări este fișierul VERSION din rădăcină.
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0-dev',

    // local | staging | production
    'stage' => env('SEVALIA_STAGE', 'local'),

    // Directorul din afara public_html pentru date persistente (documente, backup, actualizări).
    'shared_path' => env('SEVALIA_SHARED_PATH') ?: storage_path('app'),

    'security' => [
        // Rolurile pentru care MFA este obligatoriu. '*' = toate rolurile cu acces în panou.
        'mfa_required_roles' => ['*'],
        // Fereastra (minute) în care o reautentificare rămâne valabilă pentru operații sensibile.
        'reauth_minutes' => 10,
        // Durata maximă absolută a unei sesiuni de panou, indiferent de activitate (minute).
        'absolute_session_minutes' => 720,
        'login_max_attempts' => 5,
        'login_decay_seconds' => 900,
        'password_min_length' => 12,
        'blind_index_key' => env('SEVALIA_BLIND_INDEX_KEY'),
    ],

    // Biblioteca media publică (în producție: public_html/media) și cache-ul paginilor publice.
    'media_path' => env('SEVALIA_MEDIA_PATH') ?: public_path('media'),
    'media_url' => '/media',
    'site' => [
        'page_cache' => env('SEVALIA_PAGE_CACHE', null) === null ? null : (bool) env('SEVALIA_PAGE_CACHE'),
        'canonical_host' => env('SEVALIA_CANONICAL_URL', 'https://www.sevalia.ro'),
    ],

    // Surse externe permise de Content-Security-Policy. Se adaugă numai servicii confirmate.
    'csp' => [
        'frame_src' => ['https://www.google.com', 'https://maps.google.com', 'https://www.youtube-nocookie.com', 'https://www.youtube.com'],
        'img_src' => [],
        'connect_src' => [],
    ],
];
