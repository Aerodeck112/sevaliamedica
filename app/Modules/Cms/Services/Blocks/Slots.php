<?php

namespace App\Modules\Cms\Services\Blocks;

use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Services\Html\Dom;
use App\Modules\Cms\Services\Html\InlinePurifier;
use App\Modules\Cms\Services\Html\StructureSanitizer;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * „Sloturile” unui bloc: elementele editabile dintr-o secțiune cu structură blocată.
 *  - text   conținut inline (formatare limitată) al unui titlu/paragraf/element de listă etc.
 *  - link   adresa (și, dacă e simplu, textul) unui link/buton
 *  - image  imagine din biblioteca media + text alternativ
 *  - bg     imagine de fundal (stil background-image)
 * Elementele marcate data-cms-repeat (întrebări FAQ, slide-uri) pot fi duplicate sau eliminate.
 */
final class Slots
{
    private const TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'blockquote', 'figcaption', 'td', 'th', 'dt', 'dd', 'label', 'span', 'div', 'b', 'strong', 'small', 'em', 'i', 'time', 'button', 'font'];

    private const BLOCK_TAGS = ['div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'section', 'table', 'img', 'iframe', 'svg', 'form', 'input',
        'select', 'textarea', 'nav', 'figure', 'blockquote', 'header', 'footer', 'article', 'aside', 'hr', 'video', 'tr', 'td', 'tbody', 'thead'];

    private const INLINE_OK = ['b', 'strong', 'i', 'em', 'u', 's', 'br', 'span', 'a', 'font', 'sup', 'sub', 'small', 'mark', 'code'];

    private const REPEAT_CLASSES = ['accordion-item', 'carousel-item'];

    /** Marchează sloturile într-un HTML de bloc (la import). */
    public static function annotate(string $html): string
    {
        [$doc, $root] = Dom::load($html);
        $counter = 0;
        self::walk($root, $counter);

        $xp = new DOMXPath($doc);
        foreach (self::REPEAT_CLASSES as $class) {
            foreach ($xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]', $root) as $el) {
                $el->setAttribute('data-cms-repeat', $class);
            }
        }

        return Dom::inner($root);
    }

    private static function walk(DOMElement $el, int &$counter): void
    {
        foreach (iterator_to_array($el->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);

            if ($tag === 'img') {
                $child->setAttribute('data-cms-slot', (string) ++$counter);
                $child->setAttribute('data-cms-kind', 'image');

                continue;
            }
            if (in_array($tag, ['svg', 'iframe', 'script', 'form', 'select', 'textarea', 'input'], true)) {
                continue;
            }
            if (self::hasBackgroundImage($child)) {
                $child->setAttribute('data-cms-bg', (string) ++$counter);
            }
            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('data-cms-slot', (string) ++$counter);
                $child->setAttribute('data-cms-kind', self::isSimpleInline($child) && Dom::text($child) !== '' ? 'link' : 'href');
                if ($child->getAttribute('data-cms-kind') === 'href') {
                    self::walk($child, $counter);
                }

                continue;
            }
            // Un paragraf care conține doar un buton: edităm butonul ca link, nu paragraful.
            $onlyLink = self::onlyChildLink($child);
            if ($onlyLink) {
                self::walk($child, $counter);

                continue;
            }
            if (in_array($tag, self::TEXT_TAGS, true) && self::isSimpleInline($child) && Dom::text($child) !== '') {
                $child->setAttribute('data-cms-slot', (string) ++$counter);
                $child->setAttribute('data-cms-kind', 'text');

                continue;
            }
            self::walk($child, $counter);
        }
    }

    private static function hasBackgroundImage(DOMElement $el): bool
    {
        return (bool) preg_match('/background-image\s*:\s*url\(/i', $el->getAttribute('style'));
    }

    /** Conținut doar inline (fără elemente bloc și fără imagini). */
    private static function isSimpleInline(DOMElement $el): bool
    {
        foreach ($el->getElementsByTagName('*') as $d) {
            $t = strtolower($d->tagName);
            if (! in_array($t, self::INLINE_OK, true)) {
                return false;
            }
        }

        return true;
    }

    private static function onlyChildLink(DOMElement $el): ?DOMElement
    {
        $link = null;
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $t = strtolower($c->tagName);
                if ($t === 'br') {
                    continue;
                }
                if ($t !== 'a' || $link) {
                    return null;
                }
                $link = $c;
            } elseif (trim(str_replace("\u{200B}", '', (string) $c->textContent)) !== '') {
                return null;
            }
        }

        return $link;
    }

    /**
     * Lista sloturilor pentru editor.
     *
     * @return list<array{id:string, kind:string, label:string, value:mixed, repeat:?string}>
     */
    public static function extract(string $html): array
    {
        [$doc, $root] = Dom::load($html);
        $xp = new DOMXPath($doc);
        $out = [];
        foreach ($xp->query('.//*[@data-cms-slot or @data-cms-bg]', $root) as $el) {
            /** @var DOMElement $el */
            $repeat = null;
            for ($p = $el; $p && $p !== $root; $p = $p->parentNode) {
                if ($p instanceof DOMElement && $p->hasAttribute('data-cms-repeat')) {
                    $repeat = $p->getAttribute('data-cms-repeat-id') ?: null;
                    break;
                }
            }
            if ($el->hasAttribute('data-cms-bg')) {
                $out[] = ['id' => 'bg'.$el->getAttribute('data-cms-bg'), 'kind' => 'bg', 'label' => 'Imagine de fundal',
                    'value' => ['media_id' => (int) $el->getAttribute('data-cms-media-bg') ?: null, 'url' => self::bgUrl($el)], 'repeat' => $repeat];
            }
            if (! $el->hasAttribute('data-cms-slot')) {
                continue;
            }
            $id = $el->getAttribute('data-cms-slot');
            $kind = $el->getAttribute('data-cms-kind');
            $tag = strtolower($el->tagName);
            $value = match ($kind) {
                'image' => ['media_id' => (int) $el->getAttribute('data-cms-media') ?: null, 'alt' => $el->getAttribute('alt'), 'src' => $el->getAttribute('src')],
                'link' => ['href' => $el->getAttribute('href'), 'text' => trim(Dom::inner($el)), 'new_tab' => $el->getAttribute('target') === '_blank'],
                'href' => ['href' => $el->getAttribute('href'), 'new_tab' => $el->getAttribute('target') === '_blank'],
                default => trim(Dom::inner($el)),
            };
            $out[] = ['id' => $id, 'kind' => $kind, 'label' => self::label($tag, $kind, Dom::text($el)), 'value' => $value, 'repeat' => $repeat];
        }

        return $out;
    }

    private static function label(string $tag, string $kind, string $text): string
    {
        $excerpt = Str::limit($text, 60);

        return match (true) {
            $kind === 'image' => 'Imagine',
            $kind === 'link' => 'Buton / link'.($excerpt ? ': „'.$excerpt.'”' : ''),
            $kind === 'href' => 'Adresă link',
            in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) => 'Titlu '.strtoupper($tag).($excerpt ? ': „'.$excerpt.'”' : ''),
            $tag === 'li' => 'Element de listă',
            default => 'Text'.($excerpt ? ': „'.$excerpt.'”' : ''),
        };
    }

    private static function bgUrl(DOMElement $el): ?string
    {
        return preg_match('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', html_entity_decode($el->getAttribute('style')), $m) ? $m[2] : null;
    }

    /**
     * Aplică valorile trimise din editor. Structura nu se schimbă; valorile sunt sanitizate.
     *
     * @param  array<string, mixed>  $values  id slot => valoare
     */
    public static function apply(string $html, array $values): string
    {
        [$doc, $root] = Dom::load($html);
        $xp = new DOMXPath($doc);

        foreach ($xp->query('.//*[@data-cms-slot]', $root) as $el) {
            /** @var DOMElement $el */
            $id = $el->getAttribute('data-cms-slot');
            if (! array_key_exists($id, $values)) {
                continue;
            }
            $value = $values[$id];
            switch ($el->getAttribute('data-cms-kind')) {
                case 'text':
                    Dom::setInner($el, InlinePurifier::inline((string) $value));
                    break;
                case 'link':
                case 'href':
                    $href = trim((string) ($value['href'] ?? ''));
                    if ($href !== '' && StructureSanitizer::safeUrl($href)) {
                        $el->setAttribute('href', $href);
                    }
                    if (! empty($value['new_tab'])) {
                        $el->setAttribute('target', '_blank');
                        $el->setAttribute('rel', 'noopener');
                    } else {
                        $el->removeAttribute('target');
                    }
                    if ($el->getAttribute('data-cms-kind') === 'link' && isset($value['text'])) {
                        Dom::setInner($el, InlinePurifier::inline((string) $value['text']));
                    }
                    break;
                case 'image':
                    $media = isset($value['media_id']) && $value['media_id'] ? Media::find((int) $value['media_id']) : null;
                    if ($media && $media->isImage()) {
                        $el->setAttribute('src', $media->url());
                        $el->setAttribute('data-cms-media', (string) $media->id);
                    }
                    if (isset($value['alt'])) {
                        $el->setAttribute('alt', Str::limit(strip_tags((string) $value['alt']), 300, ''));
                    }
                    break;
            }
        }
        foreach ($xp->query('.//*[@data-cms-bg]', $root) as $el) {
            $id = 'bg'.$el->getAttribute('data-cms-bg');
            $mediaId = $values[$id]['media_id'] ?? null;
            if ($mediaId && ($media = Media::find((int) $mediaId)) && $media->isImage()) {
                $style = preg_replace('/url\(\s*([\'"]?).*?\1\s*\)/i', 'url("'.$media->url().'")', $el->getAttribute('style'), 1);
                $el->setAttribute('style', $style);
                $el->setAttribute('data-cms-media-bg', (string) $media->id);
            }
        }
        StructureSanitizer::clean($root);

        return Dom::inner($root);
    }

    /** Duplică elementul repetabil cu id-ul dat (ex. o întrebare FAQ), cu sloturi și id-uri noi. */
    public static function duplicateRepeat(string $html, string $repeatId): string
    {
        [$doc, $root] = Dom::load($html);
        $xp = new DOMXPath($doc);
        $max = 0;
        foreach ($xp->query('.//*[@data-cms-slot]', $root) as $el) {
            $max = max($max, (int) $el->getAttribute('data-cms-slot'));
        }
        foreach ($xp->query('.//*[@data-cms-bg]', $root) as $el) {
            $max = max($max, (int) $el->getAttribute('data-cms-bg'));
        }
        $target = $xp->query('.//*[@data-cms-repeat-id="'.addslashes($repeatId).'"]', $root)->item(0);
        if (! $target instanceof DOMElement) {
            return $html;
        }
        /** @var DOMElement $clone */
        $clone = $target->cloneNode(true);
        $suffix = Str::lower(Str::random(5));
        $clone->setAttribute('data-cms-repeat-id', 'r'.$suffix);
        Dom::removeClass($clone, 'active');
        foreach ((new DOMXPath($doc))->query('.//*[@data-cms-slot] | .//*[@data-cms-bg] | .//*[@id] | .//*[@data-bs-target] | .//*[@aria-controls] | .//*[@aria-labelledby] | .//*[@for]', $clone) as $el) {
            if ($el->hasAttribute('data-cms-slot')) {
                $el->setAttribute('data-cms-slot', (string) ++$max);
            }
            if ($el->hasAttribute('data-cms-bg')) {
                $el->setAttribute('data-cms-bg', (string) ++$max);
            }
            foreach (['id', 'aria-controls', 'aria-labelledby', 'for'] as $attr) {
                if ($el->hasAttribute($attr)) {
                    $el->setAttribute($attr, $el->getAttribute($attr).'-'.$suffix);
                }
            }
            if ($el->hasAttribute('data-bs-target') && str_starts_with($el->getAttribute('data-bs-target'), '#')) {
                $el->setAttribute('data-bs-target', $el->getAttribute('data-bs-target').'-'.$suffix);
            }
        }
        $target->parentNode->insertBefore($clone, $target->nextSibling);

        return Dom::inner($root);
    }

    public static function removeRepeat(string $html, string $repeatId): string
    {
        [$doc, $root] = Dom::load($html);
        $xp = new DOMXPath($doc);
        $target = $xp->query('.//*[@data-cms-repeat-id="'.addslashes($repeatId).'"]', $root)->item(0);
        if ($target instanceof DOMElement) {
            $siblings = $xp->query('./*[@data-cms-repeat]', $target->parentNode)->length;
            if ($siblings > 1) {
                $wasActive = Dom::hasClass($target, 'active');
                $parent = $target->parentNode;
                $parent->removeChild($target);
                if ($wasActive && ($first = $xp->query('./*[@data-cms-repeat]', $parent)->item(0))) {
                    Dom::addClass($first, 'active');
                }
            }
        }

        return Dom::inner($root);
    }

    /**
     * Atribuie id-uri stabile elementelor repetabile (apelat după annotate și după fiecare salvare).
     * Cu $seed, id-urile sunt deterministe (importul repetat produce același rezultat).
     */
    public static function ensureRepeatIds(string $html, ?string $seed = null): string
    {
        [$doc, $root] = Dom::load($html);
        $changed = false;
        $n = 0;
        foreach ((new DOMXPath($doc))->query('.//*[@data-cms-repeat]', $root) as $el) {
            $n++;
            if (! $el->hasAttribute('data-cms-repeat-id')) {
                $el->setAttribute('data-cms-repeat-id', 'r'.($seed !== null ? substr(sha1($seed.'|'.$n), 0, 6) : Str::lower(Str::random(6))));
                $changed = true;
            }
        }

        return $changed ? Dom::inner($root) : $html;
    }

    /** Lista elementelor repetabile: [repeatId => eticheta]. */
    public static function repeats(string $html): array
    {
        [$doc, $root] = Dom::load($html);
        $out = [];
        foreach ((new DOMXPath($doc))->query('.//*[@data-cms-repeat-id]', $root) as $el) {
            $out[$el->getAttribute('data-cms-repeat-id')] = ($el->getAttribute('data-cms-repeat') === 'carousel-item' ? 'Slide: ' : 'Element: ').Str::limit(Dom::text($el), 50);
        }

        return $out;
    }
}
