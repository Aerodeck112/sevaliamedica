<?php

namespace App\Modules\Cms\Console;

use App\Core\Audit\Audit;
use App\Modules\Cms\Services\Import\SiteImporter;
use Illuminate\Console\Command;

/**
 * Importă conținutul siteului original din sursele locale (arhiva database/content/odoo-archive și instantaneul
 * database/content/odoo-snapshot). Nu accesează rețeaua. Rulare repetată sigură: paginile modificate din CMS nu sunt
 * suprascrise. Orice resursă negăsită local este listată explicit.
 */
class ImportSite extends Command
{
    protected $signature = 'sevalia:import-site
        {--fara-variante : Nu generează variantele WebP (mai rapid; doar pentru verificări)}
        {--buget= : Durata maximă în secunde; dacă nu termină, raportează INCOMPLET și se reia cu aceeași comandă}';

    /** Codul de ieșire când importul s-a oprit la bugetul de timp (instalarea din browser îl reia). */
    public const INCOMPLETE = 3;

    protected $description = 'Importă paginile, articolele, meniul, subsolul, imaginile și documentele siteului original';

    public function handle(SiteImporter $importer): int
    {
        $this->info('Import în curs…');
        $budget = (float) $this->option('buget');
        $report = $importer->run(! $this->option('fara-variante'), $budget > 0 ? microtime(true) + $budget : null);
        if (! $report['complet']) {
            $this->line("INCOMPLET {$report['resurse_procesate']}/{$report['resurse_total']}");

            return self::INCOMPLETE;
        }
        $templates = $importer->saveTemplates();

        $this->line('Surse: '.$report['surse']);
        $this->table(['Rezultat', 'Număr'], [
            ['Resurse (imagini, documente) în bibliotecă', $report['imagini']],
            ['Pagini create', count($report['pagini_create'])],
            ['Pagini actualizate', count($report['pagini_actualizate'])],
            ['Pagini neschimbate', count($report['pagini_neschimbate'])],
            ['Pagini editate din CMS (sărite)', count($report['pagini_editate_sarite'])],
            ['Șabloane de secțiune', $templates],
            ['Resurse lipsă (negăsite local)', count($report['imagini_lipsa'])],
        ]);
        foreach ($report['pagini_editate_sarite'] as $path) {
            $this->warn("Sărită (editată din CMS): $path");
        }
        if ($report['imagini_lipsa']) {
            $this->error('Resurse referite de conținut, dar care lipsesc din arhivă și din instantaneu:');
            foreach ($report['imagini_lipsa'] as $missing) {
                $this->line("  lipsă: $missing");
            }
        }
        foreach ($report['atentionari'] as $w) {
            $this->warn($w);
        }
        Audit::log('cms.import', context: [
            'create' => count($report['pagini_create']),
            'actualizate' => count($report['pagini_actualizate']),
            'sarite' => count($report['pagini_editate_sarite']),
            'lipsa' => count($report['imagini_lipsa']),
        ]);

        return self::SUCCESS;
    }
}
