<?php

use App\Modules\Cms\Services\FooterChanges;
use App\Modules\Cms\Services\PageService;
use App\Modules\Cms\Services\SiteFixes;
use Illuminate\Database\Migrations\Migration;

/**
 * Auditul siteului cerut de clinică (2026-11-02): H1, corecturi de text, titluri, paginile /neurologie,
 * /dry-needling și /locatii, locația Band (adresa confirmată și pe harta din subsol), meniul „Locații”.
 * Detalii în App\Modules\Cms\Services\SiteFixes. La o instalare nouă (fără conținut încă) nu face nimic: aceleași
 * corecturi se aplică după importul arhivei. Migrarea rulează în timpul actualizării cu codul vechi, deci clasele
 * noi se încarcă din versiunea care se instalează.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['FooterChanges' => FooterChanges::class, 'SiteFixes' => SiteFixes::class] as $file => $class) {
            if (! class_exists($class)) {
                require_once __DIR__.'/../../app/Modules/Cms/Services/'.$file.'.php';
            }
        }
        if (! \App\Modules\Cms\Models\Page::where('path', '/')->exists()) {
            return;
        }
        $pages = app(PageService::class);
        FooterChanges::publish($pages);
        SiteFixes::run($pages);
    }

    public function down(): void
    {
        // Versiunile anterioare ale paginilor rămân în istoricul CMS.
    }
};
