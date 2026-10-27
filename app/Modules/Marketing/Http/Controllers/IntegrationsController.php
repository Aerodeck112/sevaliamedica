<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Marketing\Models\IndexSubmission;
use App\Modules\Marketing\Models\SeoKeyword;
use App\Modules\Marketing\Services\IndexNowService;
use App\Modules\Marketing\Services\SeoAuditService;
use App\Modules\Site\Services\ConsentConfig;
use App\Modules\Site\Services\PageCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Integrările de vizibilitate: verificarea în Google Search Console și Bing, profilul Google Business, IndexNow,
 * llms.txt și accesul crawlerelor AI. Nu stochează parole sau tokenuri Google: verificarea se face prin eticheta
 * meta, iar cererile de indexare către Google rămân în Search Console (Google nu oferă un API general pentru ele).
 */
class IntegrationsController extends Controller
{
    public const AI_MODES = [
        'permite' => 'Permite toți asistenții AI (vizibilitate maximă în ChatGPT, Claude, Perplexity, Gemini)',
        'doar_cautare' => 'Permite căutarea AI, blochează antrenarea modelelor pe conținutul siteului',
        'blocheaza' => 'Blochează toți crawlerii AI',
    ];

    public function __construct(private readonly Settings $settings, private readonly IndexNowService $indexNow) {}

    public function edit(): View
    {
        $base = SeoAuditService::base();
        $property = (string) $this->settings->get('marketing.gsc_property', '');

        return view('marketing::integrations', [
            's' => [
                'google' => $this->settings->get('marketing.verification.google'),
                'bing' => $this->settings->get('marketing.verification.bing'),
                'gsc_property' => $property,
                'gbp_url' => $this->settings->get('marketing.gbp_url'),
                'indexnow' => (bool) $this->settings->get('marketing.indexnow.enabled', false),
                'llms' => (bool) $this->settings->get('marketing.llms_enabled', true),
                'llms_summary' => $this->settings->get('marketing.llms_summary'),
                'llms_notes' => $this->settings->get('marketing.llms_notes'),
                'ai' => $this->settings->get('marketing.ai_crawlers', 'permite'),
            ],
            'base' => $base,
            'indexNowKeyUrl' => $base.'/'.$this->indexNow->key().'.txt',
            'pending' => $this->indexNow->pending(),
            'submissions' => IndexSubmission::latest()->limit(8)->get(),
            'production' => config('sevalia.stage') === 'production',
            'ga4' => collect(ConsentConfig::tags())->filter(fn ($t) => $t['active'])->values(),
            'inspect' => SeoKeyword::where('is_active', true)->distinct()->orderBy('target_path')->pluck('target_path')->prepend('/')->unique()
                ->map(fn ($p) => ['path' => $p, 'url' => $property !== ''
                    ? 'https://search.google.com/search-console/inspect?resource_id='.rawurlencode($property).'&id='.rawurlencode($base.($p === '/' ? '/' : $p))
                    : null]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'google' => ['nullable', 'string', 'max:300'],
            'bing' => ['nullable', 'string', 'max:300'],
            'gsc_property' => ['nullable', 'string', 'max:200', 'regex:#^(sc-domain:[a-z0-9.-]+|https://[^\s]+/)$#i'],
            'gbp_url' => ['nullable', 'url', 'max:500', 'regex:#^https://#'],
            'indexnow' => ['boolean'],
            'llms' => ['boolean'],
            'llms_summary' => ['nullable', 'string', 'max:300'],
            'llms_notes' => ['nullable', 'string', 'max:2000'],
            'ai' => ['required', Rule::in(array_keys(self::AI_MODES))],
        ], [
            'gsc_property.regex' => 'Proprietatea are forma „sc-domain:sevalia.ro” sau „https://www.sevalia.ro/”.',
        ]);

        foreach (['google', 'bing'] as $field) {
            if (trim((string) ($data[$field] ?? '')) !== '' && self::verificationToken($data[$field]) === null) {
                throw ValidationException::withMessages([$field => 'Codul de verificare nu a fost recunoscut. Copiați eticheta meta întreagă sau doar valoarea „content”.']);
            }
        }
        $this->settings->set('marketing.verification.google', self::verificationToken($data['google'] ?? null));
        $this->settings->set('marketing.verification.bing', self::verificationToken($data['bing'] ?? null));
        $this->settings->set('marketing.gsc_property', $data['gsc_property'] ?: null);
        $this->settings->set('marketing.gbp_url', $data['gbp_url'] ?: null);
        $this->settings->set('marketing.indexnow.enabled', (bool) ($data['indexnow'] ?? false));
        $this->settings->set('marketing.llms_enabled', (bool) ($data['llms'] ?? false));
        $this->settings->set('marketing.llms_summary', trim(strip_tags((string) ($data['llms_summary'] ?? ''))) ?: null);
        $this->settings->set('marketing.llms_notes', trim(strip_tags((string) ($data['llms_notes'] ?? ''))) ?: null);
        $this->settings->set('marketing.ai_crawlers', $data['ai']);
        PageCache::flush();
        Audit::log('marketing.integrations_updated', context: [
            'google' => (bool) $data['google'], 'bing' => (bool) $data['bing'], 'indexnow' => (bool) ($data['indexnow'] ?? false),
            'llms' => (bool) ($data['llms'] ?? false), 'ai' => $data['ai'],
        ]);

        return back()->with('status', 'Setările de vizibilitate au fost salvate.');
    }

    /** Pune în coadă toate paginile publicate și trimite imediat primul lot (doar în producție). */
    public function submitAll(Request $request): RedirectResponse
    {
        if (! $this->indexNow->enabled()) {
            return back()->withErrors(['indexnow' => 'Activați întâi IndexNow și salvați.']);
        }
        $count = $this->indexNow->queueAll();
        $submission = $this->indexNow->flush($request->user());
        Audit::log('marketing.indexnow_submitted', context: ['adrese' => $count]);

        return back()->with('status', $submission
            ? "Trimise {$submission->urls_count} adrese către IndexNow: {$submission->result}."
            : "{$count} adrese puse în coadă. Se trimit automat doar din siteul de producție.");
    }

    /** Acceptă fie codul, fie eticheta meta întreagă copiată din Search Console / Bing. */
    public static function verificationToken(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $input, $m)) {
            $input = $m[1];
        }

        return preg_match('/^[A-Za-z0-9_\-]{10,120}$/', $input) ? $input : null;
    }
}
