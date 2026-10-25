<?php

namespace App\Modules\Cms\Services\Html;

use DOMElement;
use DOMNode;

/**
 * Curăță structura secțiunilor importate (HTML-ul siteului original): elimină orice cod executabil și atributele
 * folosite doar de editorul Odoo, păstrând clasele și stilurile de care depinde aspectul.
 *
 * Structura blocurilor nu poate fi trimisă de utilizator: editorul trimite doar valorile sloturilor, care trec
 * separat prin HTMLPurifier (vezi InlinePurifier). Sanitizatorul de aici este o a doua barieră, aplicată la import
 * și la fiecare salvare.
 */
final class StructureSanitizer
{
    /** Elemente eliminate cu tot conținutul. */
    private const DROP = ['script', 'style', 'object', 'embed', 'applet', 'base', 'link', 'meta', 'noscript', 'template', 'frame', 'frameset', 'math', 'foreignobject', 'animate', 'set', 'animatemotion', 'animatetransform'];

    /** Elemente SVG inline permise (ex. săgețile din „pașii procesului”). */
    private const SVG_ALLOWED = ['svg', 'defs', 'marker', 'path', 'line', 'polyline', 'polygon', 'circle', 'ellipse', 'rect', 'g', 'use', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'title'];

    /**
     * Atribute de editor Odoo, fără efect public. Atributele folosite de CSS (data-vcss, data-vxml, data-shape,
     * data-oe-shape-data, data-aspect-ratio, data-snippet) se păstrează.
     */
    private const EDITOR_ATTRIBUTE_PREFIXES = ['data-oe-expression', 'data-oe-model', 'data-oe-id', 'data-oe-field', 'data-oe-xpath', 'data-oe-t-',
        'data-oe-translation', 'data-original-', 'data-bs-original-title', 'data-mimetype', 'data-format-', 'data-attachment-id',
        'data-resize-', 'data-quality', 'data-file-name', 'data-x', 'data-y', 'data-scale-', 'data-gl-filter', 'data-filter-options',
        'data-editor-', 'contenteditable', 'data-res-', 'data-use_'];

    /** Gazdele permise pentru iframe (hărți și video). */
    private const IFRAME_HOSTS = ['maps.google.com', 'www.google.com', 'www.youtube.com', 'www.youtube-nocookie.com', 'youtube.com'];

    public static function cleanHtml(string $html): string
    {
        [$doc, $root] = Dom::load($html);
        self::clean($root);

        return Dom::inner($root);
    }

    public static function clean(DOMNode $node, bool $insideSvg = false): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true) || str_contains($tag, ':') || ($insideSvg && ! in_array($tag, self::SVG_ALLOWED, true))) {
                $node->removeChild($child);

                continue;
            }
            if ($tag === 'iframe' && ! self::allowedIframe($child->getAttribute('src'))) {
                $node->removeChild($child);

                continue;
            }
            self::cleanAttributes($child);
            if ($tag === 'use' && ! str_starts_with((string) ($child->getAttribute('href') ?: $child->getAttribute('xlink:href')), '#')) {
                $node->removeChild($child);

                continue;
            }
            self::clean($child, $insideSvg || $tag === 'svg');
        }
    }

    private static function cleanAttributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = $attr->value;
            if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'formaction' || $name === 'xmlns') {
                $el->removeAttribute($attr->name);

                continue;
            }
            foreach (self::EDITOR_ATTRIBUTE_PREFIXES as $prefix) {
                if ($name === $prefix || str_starts_with($name, $prefix)) {
                    // păstrăm data-name doar pe elementele unde nu contează; îl eliminăm (doar pentru editorul Odoo)
                    $el->removeAttribute($attr->name);

                    continue 2;
                }
            }
            if (in_array($name, ['href', 'src', 'action', 'poster', 'data-src', 'xlink:href'], true) && ! self::safeUrl($value)) {
                $el->removeAttribute($attr->name);

                continue;
            }
            if ($name === 'style') {
                $clean = self::cleanStyle($value);
                $clean === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $clean);
            }
            if ($name === 'target' && $value === '_blank') {
                $el->setAttribute('rel', trim(($el->getAttribute('rel') ?: '').' noopener'));
            }
        }
        // Clasele se păstrează toate: și cele care par „de editor” (ex. o_editable_media) sunt folosite de CSS.
    }

    public static function safeUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url));
        $compact = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $url));
        if ($compact === '' || str_starts_with($compact, '#') || str_starts_with($compact, '/') || str_starts_with($compact, '?')) {
            return ! str_starts_with($compact, '//') || str_starts_with($compact, '//www.youtube.com');
        }
        if (preg_match('/^(https?|mailto|tel):/', $compact)) {
            return true;
        }
        // Linkuri relative fără schemă (ex. „pagina”)
        return ! preg_match('/^[a-z][a-z0-9+.-]*:/', $compact);
    }

    public static function cleanStyle(string $style): string
    {
        $lower = strtolower($style);
        if (str_contains($lower, 'expression(') || str_contains($lower, 'javascript:') || str_contains($lower, 'behavior:') || str_contains($lower, '-moz-binding') || str_contains($lower, '@import')) {
            return '';
        }
        // url(...) permis doar către resurse locale sau https
        if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $style, $m)) {
            foreach ($m[2] as $u) {
                if (! (str_starts_with($u, '/') || str_starts_with($u, 'https://') || str_starts_with($u, 'data:image/'))) {
                    return '';
                }
            }
        }

        return trim($style);
    }

    public static function allowedIframe(string $src): bool
    {
        $src = html_entity_decode($src);
        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }
        $host = parse_url($src, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::IFRAME_HOSTS, true) && str_starts_with(strtolower($src), 'https://');
    }
}
