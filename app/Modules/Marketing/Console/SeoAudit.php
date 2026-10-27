<?php

namespace App\Modules\Marketing\Console;

use App\Modules\Marketing\Services\SeoAuditService;
use Illuminate\Console\Command;

class SeoAudit extends Command
{
    protected $signature = 'sevalia:seo-audit {--start : pornește o rulare nouă} {--budget=20 : secunde de lucru pe apel}';

    protected $description = 'Auditul SEO automat al siteului: pornește o rulare și verifică paginile pe loturi';

    public function handle(SeoAuditService $audit): int
    {
        $run = $this->option('start') ? $audit->start('cron') : $audit->running();
        if (! $run) {
            return self::SUCCESS;
        }
        $done = $audit->process($run, (float) $this->option('budget'));
        $run->refresh();
        $this->line($done
            ? "Audit SEO #{$run->id} finalizat: scor {$run->score}, {$run->errors_count} erori, {$run->warnings_count} avertismente."
            : "Audit SEO #{$run->id}: {$run->pages_done}/{$run->pages_total} pagini verificate.");

        return self::SUCCESS;
    }
}
