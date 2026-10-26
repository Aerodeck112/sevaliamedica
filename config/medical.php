<?php

return [
    // Directorul documentelor medicale încărcate (criptate). În producție: în „shared”, în afara public_html.
    'documents_path' => env('SEVALIA_DOCUMENTS_PATH') ?: rtrim((string) env('SEVALIA_SHARED_PATH', storage_path('app')), '/').'/private/medical-documents',

    // Cheia de criptare a documentelor (32 de octeți, base64:...). Recomandat: separată de APP_KEY.
    // Dacă lipsește, se derivă din APP_KEY (HKDF) — documentat ca soluție de rezervă.
    'documents_key' => env('SEVALIA_DOCUMENTS_KEY'),

    // Limita de dimensiune pentru un document încărcat (KB).
    'max_upload_kb' => (int) env('SEVALIA_MEDICAL_MAX_UPLOAD_KB', 15360),

    // Tipuri acceptate: extensie => tipul MIME real (verificat cu finfo).
    'allowed' => [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ],

    // Latura maximă (px) la reencodarea imaginilor; păstrează proporțiile.
    'image_max_side' => 4000,
];
