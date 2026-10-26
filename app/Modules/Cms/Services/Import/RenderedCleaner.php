<?php

namespace App\Modules\Cms\Services\Import;

/**
 * Curăță HTML-ul paginilor salvate după rularea JavaScript-ului (arhiva randată în browser), ca să semene cu HTML-ul
 * servit inițial: elimină elementele și stările adăugate de scripturi (linii de evidențiere, video de fundal,
 * ferestre deschise, animații în curs, înălțimi calculate, bannerul Cookiebot, etichete de măsurare).
 * Siteul nou reface aceste comportamente cu propriul JavaScript.
 */
final class RenderedCleaner
{
    private const REMOVE_CLASSES = ['o_animated', 'o_animating', 'o_header_affixed', 'o_header_is_scrolled', 'o_transitioning', 'o_top_menu_collapse_shown'];

    public static function clean(string $html): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);

        $drop = [
            '//svg[contains(@class,"o_text_highlight_svg")]',
            '//*[contains(@class,"o_bg_video_container")]',
            '//*[starts-with(@id,"CybotCookiebot")]',
            '//script',
            '//noscript',
            '//*[contains(@class,"modal-backdrop")]',
            '//*[contains(@class,"offcanvas-backdrop")]',
        ];
        foreach ($drop as $query) {
            foreach (iterator_to_array($xp->query($query)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xp->query('//*[@class]')) as $el) {
            /** @var \DOMElement $el */
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            $isModal = in_array('modal', $classes, true);
            $isMenu = in_array('dropdown-menu', $classes, true) || in_array('offcanvas', $classes, true) || in_array('dropdown-toggle', $classes, true);
            $classes = array_filter($classes, fn ($c) => ! in_array($c, self::REMOVE_CLASSES, true) && ! (($isModal || $isMenu) && $c === 'show'));
            $el->setAttribute('class', implode(' ', $classes));
            if ($isModal) {
                $el->setAttribute('style', trim(preg_replace('/display:\s*block;?/', '', $el->getAttribute('style')).' display: none;'));
            }
        }
        foreach (iterator_to_array($xp->query('//*[@style]')) as $el) {
            /** @var \DOMElement $el */
            $style = $el->getAttribute('style');
            $style = preg_replace([
                '/visibility:\s*visible;?/i',
                '/animation-play-state:\s*[a-z]+;?/i',
                '/animation-name:\s*[a-z_\-]+;?/i',
                '/min-height:\s*[\d.]+px\s*!important;?/i',
                '/transform:\s*translate\([^)]*\);?/i',
            ], '', $style);
            trim((string) $style) === '' ? $el->removeAttribute('style') : $el->setAttribute('style', trim((string) $style));
        }

        return (string) $doc->saveHTML();
    }
}
