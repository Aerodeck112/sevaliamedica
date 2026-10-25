<?php

namespace App\Modules\Cms\Console;

use App\Core\Audit\Audit;
use App\Modules\Cms\Services\Import\SiteImporter;
use Illuminate\Console\Command;

/**
 * Importă conținutul siteului original din database/content/odoo-snapshot.
 * Rulare repetată sigură: paginile modificate din CMS nu sunt suprascrise.
 */
class ImportSite extends Command
{
    protected $signature = 'sevalia:import-site {--fara-media : Nu reimportă imaginile (folosește biblioteca media existentă)}';

    protected $description = 'Importă paginile, articolele, meniul, subsolul și imaginile siteului original';

    public function handle(SiteImporter $importer): int
    {
        $this->info('Import în curs…');
        $report = $importer->run(! $this->option('fara-media'));
        $templates = $importer->saveTemplates();

        $this->table(['Rezultat', 'Număr'], [
            ['Imagini în bibliotecă', $report['imagini']],
            ['Pagini create', count($report['pagini_create'])],
            ['Pagini actualizate', count($report['pagini_actualizate'])],
            ['Pagini neschimbate', count($report['pagini_neschimbate'])],
            ['Pagini editate din CMS (sărite)', count($report['pagini_editate_sarite'])],
            ['Șabloane de secțiune', $templates],
            ['Imagini lipsă din instantaneu', count(array_unique($report['imagini_lipsa']))],
        ]);
        foreach ($report['pagini_editate_sarite'] as $path) {
            $this->warn("Sărită (editată din CMS): $path");
        }
        foreach (array_unique($report['imagini_lipsa']) as $missing) {
            $this->warn("Imagine lipsă: $missing");
        }
        foreach ($report['atentionari'] as $w) {
            $this->warn($w);
        }
        Audit::log('cms.import', context: [
            'create' => count($report['pagini_create']),
            'actualizate' => count($report['pagini_actualizate']),
            'sarite' => count($report['pagini_editate_sarite']),
        ]);

        return self::SUCCESS;
    }
}
