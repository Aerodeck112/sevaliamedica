<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cerut de clinică (2026-11-21): până se stabilesc serviciile, programul și regulile, nicio programare de pe site nu
 * devine finală automat — toate rămân solicitări, confirmate de recepție. Se poate reactiva din Reguli programări.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'scheduling.online.auto_confirm'], ['value' => json_encode(false), 'updated_at' => now()]);
        Cache::forget('sevalia.settings');
    }

    public function down(): void {}
};
