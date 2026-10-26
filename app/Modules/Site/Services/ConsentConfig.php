<?php

namespace App\Modules\Site\Services;

use App\Core\Security\Csp;
use App\Core\Settings\Settings;

/**
 * Consimțământul pentru cookie-uri și etichetele de măsurare/marketing (decizia clinicii din 2026-10-25):
 *  - categorii: „necesare” (mereu active), „statistici”, „marketing”;
 *  - etichetele (Google tag pentru Ads/GA4, Meta Pixel) se încarcă numai după consimțământul categoriei lor,
 *    cu Google Consent Mode v2 implicit „denied”;
 *  - paginile excluse (implicit cele cu formulare) nu încarcă etichete;
 *  - CSP-ul permite domeniile unei etichete doar când aceasta e activă pe pagina curentă.
 * Nu se trimit către etichete date din formulare și nici evenimente de conversie cu date personale.
 */
final class ConsentConfig
{
    public const COOKIE = 'sevalia_consent';

    public const CATEGORIES = ['statistici' => 'Statistici', 'marketing' => 'Marketing'];

    public const TAG_TYPES = [
        'google' => 'Google tag (Google Ads AW-… sau Google Analytics 4 G-…)',
        'meta' => 'Meta Pixel (Facebook)',
    ];

    public const DEFAULT_EXCLUDED = ['/contactus', '/contactus-thank-you', '/emsella-landing-oferta-vara'];

    /** Domeniile cerute de fiecare tip de etichetă, pe directive CSP. */
    public const CSP_SOURCES = [
        'google' => [
            'script-src' => ['https://www.googletagmanager.com'],
            'connect-src' => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com', 'https://www.google.com', 'https://googleads.g.doubleclick.net', 'https://*.doubleclick.net'],
            'img-src' => ['https://*.google-analytics.com', 'https://*.googletagmanager.com', 'https://www.google.com', 'https://www.google.ro', 'https://*.doubleclick.net', 'https://googleads.g.doubleclick.net'],
            'frame-src' => ['https://td.doubleclick.net', 'https://www.googletagmanager.com'],
        ],
        'meta' => [
            'script-src' => ['https://connect.facebook.net'],
            'connect-src' => ['https://www.facebook.com', 'https://connect.facebook.net'],
            'img-src' => ['https://www.facebook.com'],
        ],
    ];

    public static function texts(): array
    {
        $saved = (array) app(Settings::class)->get('site.cookie', []);

        return array_merge([
            'title' => 'Folosim cookie-uri',
            'body' => 'Folosim cookie-uri necesare pentru funcționarea siteului. Cu acordul dumneavoastră, folosim și cookie-uri pentru statistici și marketing. Puteți schimba oricând alegerea din „Setări cookie”, în subsolul paginii.',
            'accept' => 'Accept toate',
            'reject' => 'Refuz cookie-urile opționale',
            'customize' => 'Personalizez',
            'save' => 'Salvez preferințele',
            'necessary' => 'Necesare — asigură funcționarea siteului; nu pot fi dezactivate.',
            'statistici' => 'Statistici — ne ajută să înțelegem cum este folosit siteul.',
            'marketing' => 'Marketing — măsoară eficiența reclamelor noastre.',
            'policy_url' => '/politica-de-confidentialitate',
        ], array_filter($saved, fn ($v) => is_string($v) && $v !== ''));
    }

    /** @return list<array{type:string, id:string, category:string, active:bool, excluded:list<string>}> */
    public static function tags(): array
    {
        $tags = (array) app(Settings::class)->get('site.tracking', []);

        return array_values(array_filter(array_map(fn ($t) => [
            'type' => (string) ($t['type'] ?? ''),
            'id' => (string) ($t['id'] ?? ''),
            'category' => in_array($t['category'] ?? '', array_keys(self::CATEGORIES), true) ? $t['category'] : 'marketing',
            'active' => (bool) ($t['active'] ?? false),
            'excluded' => array_values(array_filter(array_map('trim', (array) ($t['excluded'] ?? self::DEFAULT_EXCLUDED)))),
        ], $tags), fn ($t) => isset(self::TAG_TYPES[$t['type']]) && self::validId($t['type'], $t['id'])));
    }

    public static function validId(string $type, string $id): bool
    {
        return match ($type) {
            'google' => (bool) preg_match('/^(AW|G|GT|DC)-[A-Z0-9]{4,20}$/', $id),
            'meta' => (bool) preg_match('/^\d{8,20}$/', $id),
            default => false,
        };
    }

    /** Etichetele active pe pagina dată (fără cele care exclud pagina). */
    public static function activeTagsFor(string $path): array
    {
        return array_values(array_filter(self::tags(), fn ($t) => $t['active'] && ! in_array($path, $t['excluded'], true)));
    }

    /** Configurația transmisă scriptului de consimțământ + înregistrarea surselor CSP pentru pagină. */
    public static function forPage(string $path): array
    {
        $tags = self::activeTagsFor($path);
        self::registerCsp($path, $tags);

        return [
            'texts' => self::texts(),
            'tags' => array_map(fn ($t) => ['type' => $t['type'], 'id' => $t['id'], 'category' => $t['category']], $tags),
            'google' => (bool) array_filter($tags, fn ($t) => $t['type'] === 'google'),
        ];
    }

    public static function registerCsp(string $path, ?array $tags = null): void
    {
        foreach ($tags ?? self::activeTagsFor($path) as $tag) {
            foreach (self::CSP_SOURCES[$tag['type']] ?? [] as $directive => $sources) {
                Csp::allow($directive, $sources);
            }
        }
    }
}
