<?php

namespace App\Modules\Site\Services;

/**
 * Corecturi de accesibilitate la randare, fără schimbări vizibile față de siteul original:
 *  - nume accesibile pentru linkurile-pictogramă din subsol (rețele sociale, „Acasă”);
 *  - acordeoanele Odoo declară role="tablist"/"tab"/"tabpanel", deși sunt butoane de extindere: titlurile
 *    devin role="button", iar rolurile de tab se elimină.
 * Conținutul din CMS rămâne neschimbat; corectura se aplică doar HTML-ului trimis vizitatorului.
 */
final class A11y
{
    private const SOCIAL = ['facebook' => 'Facebook', 'youtube' => 'YouTube', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'twitter' => 'X (Twitter)'];

    public static function fix(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        // <a ... class="... s_social_media_facebook ..."> fără aria-label
        $html = preg_replace_callback('/<a\b(?![^>]*\baria-label=)([^>]*\bclass="[^"]*\bs_social_media_([a-z]+)\b[^"]*"[^>]*)>/i',
            fn ($m) => isset(self::SOCIAL[strtolower($m[2])]) ? '<a'.$m[1].' aria-label="'.self::SOCIAL[strtolower($m[2])].'">' : $m[0], $html);
        // Pictograma „casă” din lista de rețele duce la prima pagină.
        $html = preg_replace('#<a href="/"( class="text-800")>(\s*<i class="fa fa-(?:home|house)[^"]*"[^>]*></i>\s*)</a>#i', '<a href="/"$1 aria-label="Acasă">$2</a>', $html);
        $html = preg_replace('/(<div\b[^>]*\bclass="[^"]*\baccordion\b[^"]*"[^>]*?)\s+role="tablist"/i', '$1', $html);
        $html = preg_replace('/(<a\b[^>]*?)\brole="tab"([^>]*\bdata-bs-toggle="collapse")/i', '$1role="button"$2', $html);
        $html = preg_replace('/(<a\b[^>]*\bdata-bs-toggle="collapse"[^>]*?)\brole="tab"/i', '$1role="button"', $html);
        $html = preg_replace('/(<div\b[^>]*\bclass="[^"]*\bcollapse\b[^"]*"[^>]*?)\s+role="tabpanel"/i', '$1', $html);
        $html = preg_replace('/<div\b([^>]*?)\s+role="tabpanel"([^>]*\bclass="[^"]*\bcollapse\b)/i', '<div$1$2', $html);

        return $html;
    }
}
