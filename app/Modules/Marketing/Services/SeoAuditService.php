<?php

namespace App\Modules\Marketing\Services;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Models\User;
use App\Modules\Cms\Models\Page;
use App\Modules\Marketing\Models\SeoAuditPage;
use App\Modules\Marketing\Models\SeoAuditRun;
use App\Modules\Marketing\Models\SeoKeyword;
use App\Modules\Site\Services\ConsentConfig;
use App\Modules\Site\Services\SiteView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pilotul automat de audit SEO. O rulare: (1) pornește cu lista paginilor publicate, (2) le verifică pe loturi
 * limitate în timp (cron la minut, fără procese permanente), (3) la final calculează duplicatele, potrivirea
 * cuvintelor cheie și verificările de site. Rezultatele se compară cu rularea anterioară.
 */
class SeoAuditService
{
    public function __construct(
        private readonly PageAnalyzer $analyzer,
        private readonly SiteView $view,
        private readonly Settings $settings,
    ) {}

    public function running(): ?SeoAuditRun
    {
        return SeoAuditRun::where('status', 'in_curs')->latest('id')->first();
    }

    /** Pornește o rulare nouă (sau o întoarce pe cea deja pornită: o singură rulare odată). */
    public function start(string $trigger, ?User $by = null): SeoAuditRun
    {
        return DB::transaction(function () use ($trigger, $by) {
            if ($run = SeoAuditRun::where('status', 'in_curs')->lockForUpdate()->latest('id')->first()) {
                // O rulare blocată de peste o oră (ex. întreruptă) se închide și se pornește una nouă.
                if ($run->updated_at->gt(now()->subHour())) {
                    return $run;
                }
                $run->forceFill(['status' => 'esuat', 'finished_at' => now()])->save();
            }
            $pages = self::auditablePages()->get(['id', 'path']);
            $run = SeoAuditRun::create([
                'trigger' => $trigger, 'started_by' => $by?->id, 'status' => 'in_curs',
                'pages_total' => $pages->count(), 'started_at' => now(),
            ]);
            $now = now();
            foreach ($pages->chunk(200) as $chunk) {
                SeoAuditPage::insert($chunk->map(fn ($p) => [
                    'seo_audit_run_id' => $run->id, 'page_id' => $p->id, 'path' => $p->path, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            }
            if ($by) {
                Audit::log('marketing.seo_audit_started', $run);
            }

            return $run;
        });
    }

    /**
     * Verifică următoarele pagini până la expirarea bugetului de timp. Întoarce true când rularea s-a încheiat.
     */
    public function process(SeoAuditRun $run, float $budgetSeconds = 20, int $maxPages = 250): bool
    {
        if ($run->status !== 'in_curs') {
            return true;
        }
        $deadline = microtime(true) + $budgetSeconds;
        if (! app()->bound('csp-nonce')) {
            app()->instance('csp-nonce', 'audit');
        }
        $base = self::base();
        $done = 0;
        foreach (SeoAuditPage::where('seo_audit_run_id', $run->id)->whereNull('checked_at')->orderBy('id')->limit($maxPages)->get() as $row) {
            $this->check($row, $base);
            $done++;
            if (microtime(true) > $deadline) {
                break;
            }
        }
        $run->forceFill(['pages_done' => SeoAuditPage::where('seo_audit_run_id', $run->id)->whereNotNull('checked_at')->count()])->save();
        if (SeoAuditPage::where('seo_audit_run_id', $run->id)->whereNull('checked_at')->exists()) {
            return false;
        }
        $this->finish($run);

        return true;
    }

    private function check(SeoAuditPage $row, string $base): void
    {
        $page = $row->page_id ? Page::with('publishedVersion')->find($row->page_id) : null;
        if (! $page || ! $page->publishedVersion || $page->status !== 'published') {
            $row->forceFill(['checked_at' => now(), 'score' => null, 'issues' => [], 'metrics' => ['skipped' => 'pagina nu mai este publicată']])->save();

            return;
        }
        try {
            $html = $this->view->page($page, $page->publishedVersion, Request::create($page->path))->render();
            $result = $this->analyzer->analyze($html, $page->path, $base, $page->kind === Page::KIND_ARTICLE ? 'article' : 'page');
            if (empty($page->publishedVersion->seo['description']) && ($result['metrics']['description'] ?? '') !== '') {
                $result['issues'][] = ['code' => 'desc_auto', 'severity' => 'info', 'message' => PageAnalyzer::RULES['desc_auto'][2]];
            }
            $metrics = $result['metrics'] + ['text_sample' => mb_substr($result['text'], 0, 30000)];
            $row->forceFill([
                'title' => $page->title, 'issues' => $result['issues'], 'metrics' => $metrics,
                'score' => PageAnalyzer::score($result['issues']), 'checked_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $row->forceFill(['title' => $page->title, 'checked_at' => now(), 'score' => 0,
                'issues' => [['code' => 'render_failed', 'severity' => 'error', 'message' => 'Pagina nu a putut fi randată pentru verificare.']]])->save();
        }
    }

    private function finish(SeoAuditRun $run): void
    {
        $rows = SeoAuditPage::where('seo_audit_run_id', $run->id)->whereNotNull('score')->get();

        // Titluri și descrieri duplicate între paginile indexabile.
        foreach (['title' => 'title_duplicate', 'description' => 'desc_duplicate'] as $field => $code) {
            $groups = $rows->filter(fn ($r) => empty($r->metrics['noindex']) && ($r->metrics[$field] ?? '') !== '')
                ->groupBy(fn ($r) => mb_strtolower($r->metrics[$field]));
            foreach ($groups->filter(fn ($g) => $g->count() > 1) as $group) {
                foreach ($group as $r) {
                    $others = $group->where('id', '!=', $r->id)->pluck('path')->take(3)->implode(', ');
                    $issues = $r->issues;
                    $issues[] = ['code' => $code, 'severity' => 'warn', 'message' => sprintf(PageAnalyzer::RULES[$code][2], $others)];
                    $r->forceFill(['issues' => $issues, 'score' => PageAnalyzer::score($issues)])->save();
                }
            }
        }

        $byPath = $rows->keyBy('path');
        $keywords = [];
        foreach (SeoKeyword::where('is_active', true)->orderBy('target_path')->orderBy('phrase')->get() as $kw) {
            $row = $byPath->get($kw->target_path);
            if (! $row) {
                $keywords[] = ['phrase' => $kw->phrase, 'path' => $kw->target_path, 'score' => 0, 'fields' => [], 'problem' => 'Pagina țintă nu există sau nu este publicată.'];

                continue;
            }
            $m = $row->metrics;
            $match = KeywordMatcher::match($kw->phrase, ['title' => $m['title'] ?? '', 'h1' => $m['h1'] ?? '', 'description' => $m['description'] ?? '', 'text' => $m['text_sample'] ?? '']);
            $keywords[] = ['phrase' => $kw->phrase, 'path' => $kw->target_path, 'score' => $match['score'], 'fields' => $match['fields'], 'problem' => ! empty($m['noindex']) ? 'Pagina țintă este exclusă din indexare (noindex).' : null];
        }

        $rows = $rows->fresh();
        $errors = $warnings = 0;
        foreach ($rows as $r) {
            $errors += $r->countBy('error');
            $warnings += $r->countBy('warn');
        }
        $site = $this->siteChecks();
        $run->forceFill([
            'status' => 'finalizat', 'finished_at' => now(),
            'score' => $rows->isEmpty() ? null : (int) round($rows->avg('score')),
            'errors_count' => $errors + count(array_filter($site, fn ($c) => $c['severity'] === 'error')),
            'warnings_count' => $warnings + count(array_filter($site, fn ($c) => $c['severity'] === 'warn')),
            'site_checks' => ['site' => $site, 'keywords' => $keywords],
        ])->save();

        // Textul paginilor a fost folosit doar pentru potrivirea cuvintelor cheie; nu se păstrează.
        foreach ($rows as $r) {
            $m = $r->metrics;
            unset($m['text_sample']);
            $r->forceFill(['metrics' => $m])->save();
        }
        // Se păstrează istoricul ultimelor 30 de rulări.
        SeoAuditRun::where('id', '<', $run->id)->orderByDesc('id')->skip(30)->take(1000)->get()->each->delete();
    }

    /** @return list<array{code: string, severity: string, message: string}> */
    public function siteChecks(): array
    {
        $checks = [];
        $add = function (string $code, string $severity, string $message) use (&$checks) {
            $checks[] = compact('code', 'severity', 'message');
        };
        if (config('sevalia.stage') !== 'production') {
            $add('robots_blocked', 'warn', 'Siteul rulează în mod de test: robots.txt blochează indexarea (corect pentru staging; în producție se deschide automat).');
        }
        if (! str_starts_with(self::base(), 'https://')) {
            $add('no_https', 'error', 'Adresa canonică a siteului nu folosește HTTPS.');
        }
        if (! $this->settings->get('marketing.verification.google')) {
            $add('gsc_missing', 'warn', 'Google Search Console nu este verificat (codul de verificare lipsește).');
        }
        if (! $this->settings->get('marketing.gbp_url')) {
            $add('gbp_missing', 'warn', 'Linkul profilului Google Business nu este completat (contează pentru căutările locale „… Târgu Mureș”).');
        }
        if (! $this->settings->get('marketing.indexnow.enabled')) {
            $add('indexnow_off', 'info', 'IndexNow este oprit: Bing și celelalte motoare compatibile nu sunt anunțate automat la publicare.');
        }
        if (! $this->settings->get('marketing.llms_enabled', true)) {
            $add('llms_off', 'info', 'Fișierul llms.txt pentru asistenții AI este dezactivat.');
        }
        if (! collect(ConsentConfig::tags())->contains(fn ($t) => $t['active'] && str_starts_with($t['id'], 'G-'))) {
            $add('ga4_missing', 'info', 'Google Analytics 4 nu este configurat — nu veți vedea de unde vin vizitatorii.');
        }
        if (SeoKeyword::where('is_active', true)->doesntExist()) {
            $add('keywords_missing', 'warn', 'Nu este urmărit niciun cuvânt cheie.');
        }

        return $checks;
    }

    public static function auditablePages()
    {
        return Page::published()->whereIn('kind', [Page::KIND_PAGE, Page::KIND_ARTICLE])->orderBy('path');
    }

    public static function base(): string
    {
        return rtrim((string) config('sevalia.site.canonical_host', config('app.url')), '/');
    }
}
