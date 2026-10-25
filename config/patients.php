<?php

return [
    // Fereastra în care o programare cu pacientul stabilește relația de îngrijire (zile în trecut / în viitor).
    'care_window_past_days' => (int) env('SEVALIA_CARE_WINDOW_PAST_DAYS', 365),
    'care_window_future_days' => (int) env('SEVALIA_CARE_WINDOW_FUTURE_DAYS', 60),
    // Durata unui acces de urgență („break-glass”).
    'break_glass_minutes' => 60,
];
