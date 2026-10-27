<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Page;
use App\Modules\Marketing\Models\SeoAuditRun;
use App\Modules\Marketing\Models\SeoKeyword;
use App\Modules\Marketing\Services\SeoAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KeywordController extends Controller
{
    public function index(): View
    {
        $run = SeoAuditRun::latestFinished();
        $results = collect($run?->site_checks['keywords'] ?? [])->keyBy('phrase');

        return view('marketing::keywords', [
            'keywords' => SeoKeyword::orderBy('target_path')->orderBy('phrase')->get(),
            'results' => $results,
            'run' => $run,
            'pages' => SeoAuditService::auditablePages()->where('kind', Page::KIND_PAGE)->pluck('title', 'path')->map(fn ($t, $p) => $p.' — '.$t),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $keyword = SeoKeyword::create($data + ['is_active' => true]);
        Audit::log('marketing.keyword_created', $keyword, ['expresie' => $keyword->phrase]);

        return back()->with('status', 'Cuvântul cheie a fost adăugat. Rezultatul apare după următorul audit.');
    }

    public function update(Request $request, SeoKeyword $keyword): RedirectResponse
    {
        $keyword->update($this->validated($request, $keyword) + ['is_active' => $request->boolean('is_active')]);
        Audit::log('marketing.keyword_updated', $keyword, ['expresie' => $keyword->phrase]);

        return back()->with('status', 'Cuvântul cheie a fost salvat.');
    }

    public function destroy(SeoKeyword $keyword): RedirectResponse
    {
        Audit::log('marketing.keyword_deleted', $keyword, ['expresie' => $keyword->phrase]);
        $keyword->delete();

        return back()->with('status', 'Cuvântul cheie a fost șters.');
    }

    private function validated(Request $request, ?SeoKeyword $keyword = null): array
    {
        return $request->validate([
            'phrase' => ['required', 'string', 'max:150', Rule::unique('seo_keywords', 'phrase')->ignore($keyword?->id)],
            'target_path' => ['required', 'string', 'max:300', 'regex:#^/#'],
        ], ['target_path.regex' => 'Alegeți o pagină a siteului.']);
    }
}
