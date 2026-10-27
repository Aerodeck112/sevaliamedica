<?php

namespace App\Modules\Marketing\Services;

use App\Core\Settings\Settings;
use App\Models\User;
use App\Modules\Marketing\Models\IndexSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * IndexNow (https://www.indexnow.org): anunță imediat adresele publicate sau modificate motoarelor care acceptă
 * protocolul (Bing, Yandex, Seznam, Naver…). Google NU folosește IndexNow; pentru Google rămân sitemapul și
 * cererea manuală de indexare din Search Console. Trimiterea se face doar din producție, din cron.
 */
class IndexNowService
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public const BATCH = 1000;

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('marketing.indexnow.enabled', false);
    }

    /** Cheia publică a siteului (servită la /<cheie>.txt), generată la prima folosire. */
    public function key(): string
    {
        $key = (string) $this->settings->get('marketing.indexnow.key', '');
        if (! preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            $this->settings->set('marketing.indexnow.key', $key);
        }

        return $key;
    }

    public function queue(string $path): void
    {
        if ($this->enabled()) {
            DB::table('index_queue')->upsert([['path' => $path, 'queued_at' => now()]], ['path'], ['queued_at']);
        }
    }

    public function queueAll(): int
    {
        $paths = SeoAuditService::auditablePages()->with('publishedVersion')->get()
            ->reject(fn ($p) => (bool) ($p->publishedVersion?->seo['noindex'] ?? false))->pluck('path');
        foreach ($paths->chunk(200) as $chunk) {
            DB::table('index_queue')->upsert($chunk->map(fn ($p) => ['path' => $p, 'queued_at' => now()])->values()->all(), ['path'], ['queued_at']);
        }

        return $paths->count();
    }

    public function pending(): int
    {
        return DB::table('index_queue')->count();
    }

    /** Trimite un lot din coadă. Întoarce înregistrarea trimiterii sau null dacă nu era nimic de trimis. */
    public function flush(?User $by = null): ?IndexSubmission
    {
        if (! $this->enabled() || config('sevalia.stage') !== 'production') {
            return null;
        }
        $rows = DB::table('index_queue')->orderBy('queued_at')->limit(self::BATCH)->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $base = SeoAuditService::base();
        $key = $this->key();
        $payload = [
            'host' => parse_url($base, PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => $base.'/'.$key.'.txt',
            'urlList' => $rows->map(fn ($r) => $base.($r->path === '/' ? '/' : $r->path))->values()->all(),
        ];
        $status = null;
        try {
            $response = Http::timeout(20)->acceptJson()->post(self::ENDPOINT, $payload);
            $status = $response->status();
            $result = match (true) {
                in_array($status, [200, 202], true) => 'acceptat pentru procesare',
                $status === 403 => 'cheie nevalidă (fișierul cheii nu este accesibil)',
                $status === 422 => 'adrese care nu aparțin domeniului',
                $status === 429 => 'prea multe cereri — se reîncearcă',
                default => 'răspuns neașteptat',
            };
        } catch (Throwable $e) {
            $result = 'eroare de rețea — se reîncearcă';
        }
        // Cererile greșite (4xx în afară de 429) nu se repetă la nesfârșit; erorile temporare rămân în coadă.
        if ($status !== null && $status < 500 && $status !== 429) {
            DB::table('index_queue')->whereIn('id', $rows->pluck('id'))->delete();
        }

        return IndexSubmission::create(['engine' => 'indexnow', 'urls_count' => $rows->count(), 'status_code' => $status, 'result' => $result, 'triggered_by' => $by?->id]);
    }
}
