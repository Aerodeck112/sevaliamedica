<?php

use App\Modules\Cms\Services\FooterChanges;
use App\Modules\Cms\Services\PageService;
use Illuminate\Database\Migrations\Migration;

/**
 * Subsolul: copyright 2026 și harta pentru medicina de familie din Band (cerute de clinică, 2026-10-28).
 * Versiune nouă publicată a subsolului, cu istoric în CMS; la o instalare nouă subsolul încă nu există (se aplică
 * după importul arhivei). Migrarea rulează în timpul actualizării cu codul vechi, deci clasa nouă se încarcă din
 * versiunea care se instalează.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(FooterChanges::class)) {
            require_once __DIR__.'/../../app/Modules/Cms/Services/FooterChanges.php';
        }
        FooterChanges::publish(app(PageService::class));
    }

    public function down(): void
    {
        // Versiunea anterioară a subsolului rămâne în istoricul paginii (Site → Pagini → Subsol → Istoric).
    }
};
