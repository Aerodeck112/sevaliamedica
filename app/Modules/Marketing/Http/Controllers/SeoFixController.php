<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Models\SeoAuditRun;
use App\Modules\Marketing\Services\SeoAuditService;
use App\Modules\Marketing\Services\SeoFixer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Corectorul SEO: propunerile pentru problemele din ultimul audit, aplicate după confirmarea omului.
 */
class SeoFixController extends Controller
{
    public function __construct(private readonly SeoFixer $fixer, private readonly SeoAuditService $audit) {}

    public function index(): View
    {
        $run = SeoAuditRun::latestFinished();

        return view('marketing::fix', [
            'run' => $run,
            'running' => $this->audit->running(),
            'proposals' => $run ? $this->fixer->proposals($run) : ['fixes' => [], 'tasks' => []],
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fix' => ['required', 'array'],
            'fix.*' => ['array'],
            'fix.*.*' => ['nullable', 'string', 'max:300'],
            'use' => ['required', 'array'],
        ], ['use.required' => 'Alegeți cel puțin o corectură.', 'fix.required' => 'Alegeți cel puțin o corectură.']);

        // Doar câmpurile bifate („use[page][field]”) se aplică.
        $values = [];
        foreach ((array) $data['use'] as $pageId => $fields) {
            foreach (array_keys((array) $fields) as $field) {
                if (in_array($field, ['title', 'description', 'noindex', 'page_role'], true)) {
                    $values[(int) $pageId][$field] = (string) ($data['fix'][$pageId][$field] ?? '');
                }
            }
        }
        if ($values === []) {
            return back()->withErrors(['use' => 'Alegeți cel puțin o corectură.']);
        }
        $result = $this->fixer->apply($values, $request->user());

        // Scorul se recalculează imediat: o verificare nouă, continuată din cron dacă nu se termină în această cerere.
        $message = count($result['applied']).' pagini corectate.';
        if ($result['skipped']) {
            $message .= ' Sărite: '.implode(', ', array_map(fn ($p, $r) => "$p ($r)", array_keys($result['skipped']), $result['skipped'])).'.';
        }
        if ($result['applied'] && ! $this->audit->running()) {
            $run = $this->audit->start('manual', $request->user());
            $this->audit->process($run, 15);
        }

        return redirect()->route('panou.marketing.seo')->with('status', $message.' Scorul se recalculează acum.');
    }
}
