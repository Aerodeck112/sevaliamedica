<?php

return [
    /*
     * În afara producției (SEVALIA_STAGE = local | staging) trimiterile reale sunt blocate: livrările se marchează
     * „simulat”. Se poate permite explicit, de exemplu pentru un test controlat pe staging cu numere de test.
     */
    'real_sending_outside_production' => (bool) env('SEVALIA_NOTIFICATIONS_REAL_SENDING', false),

    // Pauzele dintre reîncercări, în minute (încercarea 1 → 2, 2 → 3 ...).
    'backoff_minutes' => [1, 5, 30, 120, 360],

    // Cât timp poate sta o livrare „în trimitere” înainte să fie considerată cu rezultat necunoscut.
    'sending_lock_minutes' => 5,
];
