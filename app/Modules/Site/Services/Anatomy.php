<?php

namespace App\Modules\Site\Services;

use App\Core\Settings\Settings;
use App\Modules\Cms\Models\Page;

/**
 * Schițele anatomice animate ale siteului, adăugate peste conținutul original fără a-l modifica:
 *  - pe coperta paginilor de servicii, o siluetă în linie care evidențiază zona tratată;
 *  - pe prima pagină, harta „Unde simțiți disconfort?”, care duce spre paginile de terapii existente.
 * Legăturile zonă → pagină sunt orientative (navigare), nu recomandări medicale. Se pot opri din Setări site.
 */
class Anatomy
{
    /** Zonele evidențiate pe coperta fiecărei pagini. */
    public const PAGE_FOCUS = [
        '/kinetoterapie' => ['coloana', 'genunchi'],
        '/kinetoterapie-atm-articulatia-temporo-mandibulara' => ['atm'],
        '/masaj' => ['gat_umeri', 'coloana'],
        '/reabilitare-medicala-mures' => ['articulatii'],
        '/recuperare-medicala-mures' => ['sold', 'genunchi'],
        '/fizioterapie-targu-mures' => ['articulatii'],
        '/emsella-tratament-pentru-incontinenta-urinara' => ['pelvin'],
        '/emsella-landing-oferta-vara' => ['pelvin'],
        '/sunt-insarcinata' => ['pelvin', 'coloana'],
        '/decompresie-vertebrala-robotizata' => ['coloana'],
        '/super-inductive-system-sis' => ['coloana'],
        '/recuperare-postoperatorie-cpmotion' => ['sold', 'genunchi'],
        '/alterg-via-banda-antigravitationala-recuperare-medicala' => ['sold', 'genunchi'],
        '/hydro-physio-banda-de-alergat-subacvala-recuperare-rapida' => ['genunchi'],
        '/shockwave-focusat' => ['gat_umeri', 'genunchi'],
        '/tr-therapy' => ['articulatii'],
        '/kinesio-taping' => ['gat_umeri', 'genunchi'],
        '/reumatologie' => ['articulatii'],
        '/am-dureri' => ['coloana', 'gat_umeri'],
        '/am-leziuni' => ['genunchi'],
        '/vreau-sa-ma-simt-bine' => [],
        '/electroterapie' => ['articulatii'],
        '/magnetoterapie' => ['articulatii'],
        '/laserterapie' => ['articulatii'],
        '/ultrasonoterapie' => ['articulatii'],
        '/termoterapie' => ['articulatii'],
        '/bai-galvanice' => ['articulatii'],
        '/baie-stanger-electrohidroterapie' => ['articulatii'],
        '/dus-subacval' => ['coloana'],
        '/drenaj-limfatic' => [],
        '/salinoterapie' => [],
    ];

    /** Harta de pe prima pagină: zona, pagina țintă și eticheta din meniul siteului. */
    public const BODY_MAP = [
        'coloana' => ['Spate și coloană', [
            '/decompresie-vertebrala-robotizata' => 'Decompresie vertebrală robotizată',
            '/kinetoterapie' => 'Kinetoterapie',
            '/super-inductive-system-sis' => 'Super Inductive System',
            '/masaj' => 'Masaj',
        ]],
        'gat_umeri' => ['Gât și umeri', [
            '/masaj' => 'Masaj',
            '/kinesio-taping' => 'Kinesio taping',
            '/shockwave-focusat' => 'ShockWave Focusat',
            '/tr-therapy' => 'TR-Therapy (TECAR)',
        ]],
        'atm' => ['Maxilar (ATM)', [
            '/kinetoterapie-atm-articulatia-temporo-mandibulara' => 'Kinetoterapie ATM',
        ]],
        'genunchi' => ['Șold și genunchi', [
            '/recuperare-postoperatorie-cpmotion' => 'Recuperare postoperatorie – CPMotion',
            '/alterg-via-banda-antigravitationala-recuperare-medicala' => 'AlterG VIA – bandă antigravitațională',
            '/hydro-physio-banda-de-alergat-subacvala-recuperare-rapida' => 'Hydro Physio – bandă subacvală',
            '/recuperare-medicala-mures' => 'Recuperare medicală',
        ]],
        'pelvin' => ['Planșeu pelvin', [
            '/emsella-tratament-pentru-incontinenta-urinara' => 'EMSELLA – terapia planșeului pelvin',
            '/sunt-insarcinata' => 'Sunt însărcinată',
        ]],
        'articulatii' => ['Articulații și reumatism', [
            '/reumatologie' => 'Reumatologie',
            '/bai-galvanice' => 'Băi galvanice',
            '/baie-stanger-electrohidroterapie' => 'Baie Stanger',
            '/termoterapie' => 'Termoterapie',
        ]],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('site.anatomy', true);
    }

    /** Adaugă schița pe copertele (s_cover) paginii, dacă pagina are o zonă asociată. */
    public function decorate(string $html, string $path): string
    {
        if (! $this->enabled() || ! array_key_exists($path, self::PAGE_FOCUS)) {
            return $html;
        }
        $figure = '<div class="sv-anatomy sv-anatomy--hero" aria-hidden="true">'
            .view('site::partials.anatomy-figure', ['focus' => self::PAGE_FOCUS[$path]])->render().'</div>';

        $offset = 0;
        for ($i = 0; $i < 2; $i++) {
            $start = strpos($html, '<section class="s_cover', $offset);
            if ($start === false) {
                break;
            }
            $end = strpos($html, '</section>', $start);
            if ($end === false) {
                break;
            }
            $html = substr($html, 0, $end).$figure.substr($html, $end);
            $offset = $end + strlen($figure) + 10;
        }

        return $html;
    }

    /** Inserează harta corpului după a N-a secțiune de nivel superior a primei pagini. */
    public function withBodyMap(string $html): string
    {
        if (! $this->enabled() || ! $this->settings->get('site.body_map', true)) {
            return $html;
        }
        $published = Page::published()->pluck('path')->flip();
        $regions = [];
        foreach (self::BODY_MAP as $key => [$label, $links]) {
            $links = array_filter($links, fn ($p) => $published->has($p), ARRAY_FILTER_USE_KEY);
            if ($links) {
                $regions[$key] = ['label' => $label, 'links' => $links];
            }
        }
        if (! $regions) {
            return $html;
        }
        $map = view('site::partials.body-map', ['regions' => $regions])->render();
        $after = max(0, (int) $this->settings->get('site.body_map_after', 3));

        // Secțiunile de nivel superior ale conținutului (blocurile nu conțin secțiuni imbricate).
        $pos = 0;
        for ($n = 0; $n < $after; $n++) {
            $next = strpos($html, '</section>', $pos);
            if ($next === false) {
                return $html.$map;
            }
            $pos = $next + strlen('</section>');
        }

        return substr($html, 0, $pos).$map.substr($html, $pos);
    }
}
