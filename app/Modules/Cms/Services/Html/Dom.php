<?php

namespace App\Modules\Cms\Services\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Încărcare și serializare de fragmente HTML cu DOMDocument, păstrând UTF-8 (diacriticele) și elementele HTML5.
 */
final class Dom
{
    public const ROOT_ID = '__cms_root';

    /** @return array{0: DOMDocument, 1: DOMElement} documentul și elementul rădăcină care conține fragmentul */
    public static function load(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="'.self::ROOT_ID.'">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $doc->encoding = 'UTF-8';

        return [$doc, $doc->getElementById(self::ROOT_ID)];
    }

    public static function inner(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $node->ownerDocument->saveHTML($child);
        }

        return $out;
    }

    public static function setInner(DOMElement $element, string $html): void
    {
        while ($element->firstChild) {
            $element->removeChild($element->firstChild);
        }
        if ($html === '') {
            return;
        }
        [$tmp, $root] = self::load($html);
        foreach (iterator_to_array($root->childNodes) as $child) {
            $element->appendChild($element->ownerDocument->importNode($child, true));
        }
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        return new DOMXPath($doc);
    }

    public static function hasClass(DOMElement $el, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [], true);
    }

    public static function addClass(DOMElement $el, string $class): void
    {
        if (! self::hasClass($el, $class)) {
            $el->setAttribute('class', trim($el->getAttribute('class').' '.$class));
        }
    }

    public static function removeClass(DOMElement $el, string $class): void
    {
        $classes = array_filter(preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [], fn ($c) => $c !== $class && $c !== '');
        $el->setAttribute('class', implode(' ', $classes));
    }

    /** Text simplu al unui nod, cu spațiile normalizate. */
    public static function text(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $node->textContent)));
    }
}
