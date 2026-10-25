<?php

namespace Tests\Feature\Cms;

use App\Modules\Cms\Services\Import\SiteImporter;
use Database\Seeders\ClinicPublicDataSeeder;

/**
 * Importă instantaneul siteului (fără imagini, pentru viteză) în baza de test.
 */
trait ImportsSite
{
    protected function importSite(): array
    {
        $this->seed(ClinicPublicDataSeeder::class);

        return app(SiteImporter::class)->run(false);
    }

    /** @return list<string> căile publice în română din instantaneu */
    protected function snapshotPaths(): array
    {
        $pages = json_decode(file_get_contents(database_path('content/odoo-snapshot/pages.json')), true);
        $paths = [];
        foreach ($pages as $url => $info) {
            if (($info['code'] ?? null) !== 200 || ! str_starts_with($url, SiteImporter::BASE)) {
                continue;
            }
            $path = substr($url, strlen(SiteImporter::BASE)) ?: '/';
            if (str_starts_with($path, '/en') || str_contains($path, '/feed')) {
                continue;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }
}
