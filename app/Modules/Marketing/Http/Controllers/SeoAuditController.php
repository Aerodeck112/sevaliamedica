<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Models\SeoAuditPage;
use App\Modules\Marketing\Models\SeoAuditRun;
use App\Modules\Marketing\Services\SeoAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SeoAuditController extends Controller
{
    public function __construct(private readonly SeoAuditService $audit) {}

    public function index(Request $request): View
    {
        $running = $this->audit->running();
        $run = $request->filled('rulare') ? SeoAuditRun::where('status', 'finalizat')->findOrFail((int) $request->query('rulare')) : SeoAuditRun::latestFinished();
        $previous = $run ? SeoAuditRun::where('status', 'finalizat')->where('id', '<', $run->id)->latest('id')->first() : null;
        $pages = $run ? SeoAuditPage::where('seo_audit_run_id', $run->id)->whereNotNull('score')->orderBy('score')->orderBy('path')->get() : collect();
        $previousScores = $previous ? SeoAuditPage::where('seo_audit_run_id', $previous->id)->pluck('score', 'path') : collect();

        $severity = $request->query('gravitate');
        if (in_array($severity, ['error', 'warn'], true)) {
            $pages = $pages->filter(fn ($p) => $p->countBy($severity) > 0)->values();
        }
        // Problemele cele mai frecvente, ca să se vadă ce merită corectat întâi.
        $frequent = [];
        foreach ($pages as $p) {
            foreach ((array) $p->issues as $i) {
                if ($i['severity'] !== 'info') {
                    $frequent[$i['code']] ??= ['severity' => $i['severity'], 'count' => 0, 'example' => $i['message']];
                    $frequent[$i['code']]['count']++;
                }
            }
        }
        uasort($frequent, fn ($a, $b) => [$a['severity'] === 'error' ? 0 : 1, -$a['count']] <=> [$b['severity'] === 'error' ? 0 : 1, -$b['count']]);

        return view('marketing::seo', [
            'run' => $run, 'previous' => $previous, 'running' => $running, 'pages' => $pages, 'previousScores' => $previousScores,
            'frequent' => $frequent, 'history' => SeoAuditRun::where('status', 'finalizat')->latest('id')->limit(14)->get()->reverse()->values(),
            'severity' => $severity,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $run = $this->audit->start('manual', $request->user());

        return $this->work($run);
    }

    public function continue(SeoAuditRun $run): RedirectResponse
    {
        return $this->work($run);
    }

    private function work(SeoAuditRun $run): RedirectResponse
    {
        // Cât încape într-o cerere; restul îl continuă cronul (la minut) sau butonul „Continuă acum”.
        $done = $this->audit->process($run, 15);

        return redirect()->route('panou.marketing.seo')->with('status', $done
            ? 'Auditul SEO s-a încheiat.'
            : "Audit în curs: {$run->fresh()->pages_done} din {$run->pages_total} pagini verificate. Continuă automat din cron.");
    }
}
