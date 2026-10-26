<?php

namespace Tests\Feature\Cms;

use App\Modules\Cms\Console\ImportSite;
use App\Modules\Cms\Models\Page;
use Tests\TestCase;

/** Importul din instalarea web rulează pe bucăți: se oprește la buget și se reia fără pierderi. */
class ImportBudgetTest extends TestCase
{
    public function test_import_stops_at_budget_and_resumes_to_completion(): void
    {
        $this->artisan('sevalia:import-site', ['--fara-variante' => true, '--buget' => '0.000001'])
            ->expectsOutputToContain('INCOMPLET')
            ->assertExitCode(ImportSite::INCOMPLETE);
        $this->assertSame(0, Page::count());

        $this->artisan('sevalia:import-site', ['--fara-variante' => true])->assertExitCode(0);
        $this->assertGreaterThan(50, Page::count());
    }
}
