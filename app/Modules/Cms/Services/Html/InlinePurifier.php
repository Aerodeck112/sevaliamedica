<?php

namespace App\Modules\Cms\Services\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizarea textului introdus de editori (HTMLPurifier).
 *  - inline(): formatare limitată în sloturile de text (bold, italic, link, culoare, mărime).
 *  - rich(): corpul articolelor (paragrafe, titluri, liste, citate, imagini din biblioteca media, tabele simple).
 * Nu permite script, style, iframe, atribute on*, javascript: sau cod PHP (PHP nu e interpretat niciodată în conținut).
 */
final class InlinePurifier
{
    private static array $instances = [];

    public static function inline(string $html): string
    {
        return trim(self::purifier('inline')->purify($html));
    }

    public static function rich(string $html): string
    {
        return trim(self::purifier('rich')->purify($html));
    }

    private static function purifier(string $profile): HTMLPurifier
    {
        if (isset(self::$instances[$profile])) {
            return self::$instances[$profile];
        }

        $config = HTMLPurifier_Config::createDefault();
        $cache = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $config->set('Cache.SerializerPath', $cache);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'text-align']);
        $config->set('Attr.EnableID', false);

        if ($profile === 'inline') {
            $config->set('HTML.Allowed', 'b,strong,i,em,u,s,br,sup,sub,small,span[class|style],a[href|target|class|title],font[color|class|style]');
        } else {
            $config->set('HTML.Allowed', implode(',', [
                'p[class|style]', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'sup', 'sub', 'small', 'span[class|style]', 'font[color|class|style]',
                'a[href|target|class|title]', 'h2[class]', 'h3[class]', 'h4[class]', 'h5[class]', 'h6[class]', 'ul[class]', 'ol[class]', 'li[class]',
                'blockquote[class]', 'hr', 'img[src|alt|class|width|height|loading|data-cms-media]', 'table[class]', 'thead', 'tbody', 'tr', 'th[colspan|rowspan]', 'td[colspan|rowspan]',
                'div[class]',
            ]));
            // Imaginile din corpul articolelor trebuie să fie din biblioteca media locală.
            $config->set('URI.DisableExternalResources', true);
        }
        $config->set('Attr.AllowedClasses', null);
        $config->set('HTML.DefinitionID', 'sevalia-'.$profile);
        $config->set('HTML.DefinitionRev', 3);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('img', 'loading', 'Enum#lazy,eager');
            $def->addAttribute('img', 'data-cms-media', 'Number');
        }

        return self::$instances[$profile] = new HTMLPurifier($config);
    }
}
