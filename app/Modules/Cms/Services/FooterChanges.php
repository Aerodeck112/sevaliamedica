<?php

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\Page;

/**
 * Modificările subsolului cerute de clinică (2026-10-28), față de subsolul preluat de pe siteul vechi:
 *  - anul din copyright: 2023 → 2026;
 *  - a treia hartă: medicina de familie din Band, jud. Mureș.
 *
 * Se aplică o singură dată, ca versiune nouă publicată a subsolului (cu istoric în CMS): la actualizarea unei
 * instalări existente (migrare) și după importul arhivei la o instalare nouă. Sunt idempotente; după aplicare,
 * subsolul se editează normal din panou.
 *
 * Adresa exactă din Band nu apare în arhiva siteului vechi: harta indică localitatea („Band, județul Mureș”) până
 * când clinica o confirmă (docs/INTREBARI.md).
 */
final class FooterChanges
{
    public const BAND_ADDRESS = 'Band, județul Mureș';

    public static function apply(string $html): string
    {
        $html = preg_replace('~(Copyright\s+Sevalia\s+Medica\s+)2023~u', '${1}2026', $html) ?? $html;

        if (! str_contains($html, 'data-map-address="'.self::BAND_ADDRESS.'"')) {
            // După ultima hartă din subsol (Pediatrie | Neurologie | Psihiatrie): sfârșitul secțiunii ei.
            $lastMap = strrpos($html, 'class="s_map ');
            $pos = $lastMap !== false ? strpos($html, '</section>', $lastMap) : false;
            if ($pos !== false) {
                $pos += strlen('</section>');
                $html = substr($html, 0, $pos).self::bandMap().substr($html, $pos);
                // Coloana hărților din grila subsolului (rânduri de 50 px) crește cu 6 rânduri pentru a treia hartă.
                $html = preg_replace(
                    '~(o_grid_item[^"]*?)g-height-12([^"]*"\s+style="[^"]*grid-area:\s*1\s*/\s*8\s*/\s*)13(\s*/\s*13)~u',
                    '${1}g-height-18${2}19${3}', $html, 1) ?? $html;
            }
        }

        return $html;
    }

    /** Aplică modificările pe subsolul publicat. Întoarce true dacă s-a publicat o versiune nouă. */
    public static function publish(PageService $pages): bool
    {
        $footer = Page::where('path', Page::FOOTER_PATH)->first();
        $version = $footer?->publishedVersion ?? $footer?->currentVersion;
        if (! $footer || ! $version) {
            return false;
        }
        $blocks = $version->blocks;
        $changed = false;
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? null) === 'html' && is_string($block['html'] ?? null)) {
                $new = self::apply($block['html']);
                if ($new !== $block['html']) {
                    $blocks[$i]['html'] = $new;
                    $changed = true;
                }
            }
        }
        if (! $changed) {
            return false;
        }
        $new = $pages->saveVersion($footer, $blocks, (array) $version->seo, (array) $version->settings, null, null,
            'Copyright 2026 și harta pentru Band (cerute de clinică)');
        $pages->publish($footer, $new, null);

        return true;
    }

    private static function bandMap(): string
    {
        $q = rawurlencode(self::BAND_ADDRESS);

        return '<p><br></p><p><span class="o_small-fs"><strong><font style="color: rgb(0, 159, 174);">MEDICINĂ DE FAMILIE | BAND, JUD. MUREȘ</font></strong></span></p>'
            .'<section class="s_map pt56 pb128" data-map-type="m" data-snippet="s_map" data-map-address="'.self::BAND_ADDRESS.'" data-name="Hartă" data-map-zoom="13">'
            .'<div class="map_container o_not_editable">'
            .'<iframe class="s_map_embedded o_not_editable" src="https://maps.google.com/maps?q='.$q.'&amp;t=m&amp;z=13&amp;ie=UTF8&amp;iwloc=&amp;output=embed" width="100%" height="100%" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" aria-label="Hartă" loading="lazy" title="Hartă – medicina de familie Band"></iframe>'
            .'<div class="s_map_color_filter"></div></div></section>';
    }
}
