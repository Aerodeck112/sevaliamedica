<?php

namespace App\Modules\Site\Services;

/**
 * Bulinele colorate din grilele de servicii și afecțiuni (Odoo „s_features_grid”) aveau toate aceeași săgeată
 * („fa-chevron-right”). La afișare, fiecare primește o iconiță potrivită titlului de lângă ea (din același font
 * Font Awesome 4.7 al siteului original). Conținutul din CMS nu se modifică; un titlu necunoscut păstrează săgeata.
 * Glifele folosite sunt declarate în resources/css/site.css (secțiunea „Iconițe pentru buline”).
 */
final class SiteIcons
{
    /** Cuvânt din titlu (fără diacritice, litere mici) → iconiță. Ordinea contează: primul potrivit câștigă. */
    public const MAP = [
        'electroterap' => 'bolt', 'impuls' => 'bolt', 'stimul' => 'bolt',
        'salinoterap' => 'cloud', 'haloterap' => 'cloud',
        'kinesio' => 'medkit', 'kinetoterap' => 'child',
        'stanger' => 'bath', 'galvanic' => 'bath', 'dus ' => 'shower', 'subacval' => 'shower',
        'dry needling' => 'thumb-tack', 'magnetoterap' => 'magnet',
        'termoterap' => 'thermometer-half', 'termic' => 'thermometer-half',
        'drenaj' => 'tint', 'ultrason' => 'wifi', 'laser' => 'crosshairs', 'masaj' => 'hand-paper-o',
        'adjuvant' => 'plus-circle', 'neinvaziv' => 'shield', 'metabolism' => 'refresh', 'masa muscular' => 'heartbeat',
        'artrita' => 'hand-rock-o', 'artroza' => 'male', 'spondil' => 'align-justify', 'lupus' => 'sun-o',
        'guta' => 'fire', 'osteopor' => 'user',
        'ortoped' => 'wheelchair', 'neurolog' => 'sitemap', 'post-operator' => 'medkit', 'postoperator' => 'medkit',
        'reumatolog' => 'stethoscope', 'cardio' => 'heartbeat', 'hipertensiun' => 'heartbeat',
        'diabet' => 'tint', 'gastro' => 'cutlery', 'respirator' => 'leaf', 'anemi' => 'tint',
        'discuti' => 'comments', 'examinare' => 'stethoscope', 'evaluare' => 'list-alt',
        'diagnostic' => 'file-text-o', 'planul de tratament' => 'check-square-o', 'tratament' => 'check-square-o',
    ];

    public static function fix(string $html): string
    {
        if (! str_contains($html, 'rounded-circle')) {
            return $html;
        }

        $class = '[^"]*\\bfa-chevron-right\\b[^"]*\\brounded-circle\\b[^"]*|[^"]*\\brounded-circle\\b[^"]*\\bfa-chevron-right\\b[^"]*';

        return (string) preg_replace_callback(
            '~<i class="('.$class.')"([^>]*)>(\\s*</i>)(.{0,600}?)<(h[2-6])\\b([^>]*)>(.*?)</\\5>~su',
            function ($m) {
                $title = self::plain($m[7]);
                foreach (self::MAP as $needle => $icon) {
                    if (str_contains($title, $needle)) {
                        $cls = (string) preg_replace('~\\bfa-chevron-right\\b~', 'fa-'.$icon, $m[1]);

                        return '<i class="'.$cls.'"'.$m[2].' aria-hidden="true">'.$m[3].$m[4].'<'.$m[5].$m[6].'>'.$m[7].'</'.$m[5].'>';
                    }
                }

                return $m[0];
            },
            $html,
        );
    }

    private static function plain(string $html): string
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = strtr(mb_strtolower($t), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return trim((string) preg_replace('/\s+/u', ' ', $t)).' ';
    }
}
