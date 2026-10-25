<?php

return [
    // Pasul grilei calendarului (minute) și intervalul afișat implicit.
    'slot_minutes' => 15,
    'day_start' => '07:00',
    'day_end' => '22:00',

    // Programările în afara programului de lucru al persoanei sunt refuzate; cei cu scheduling.override pot forța,
    // cu motiv, și se înregistrează în istoric.
    'enforce_working_hours' => true,

    // Statusurile care rezervă resurse (persoană, sală, aparat).
    'blocking_statuses' => ['confirmata', 'sosit', 'in_desfasurare', 'finalizata'],

    // Linkurile pentru pacient (anulare / cerere de reprogramare).
    'patient_link_ttl_hours' => 24 * 14,
    // Anularea prin link nu mai e permisă cu mai puțin de atâtea ore înainte (pacientul e îndrumat să sune).
    'patient_cancel_min_hours' => 2,

    // Câte intervale libere se sugerează la mutare.
    'suggestions' => 8,
];
