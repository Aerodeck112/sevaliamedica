<?php

namespace App\Modules\Site\Services;

use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Services\Html\Dom;
use DOMElement;
use DOMXPath;

/**
 * Transformă HTML-ul blocurilor în HTML public optimizat, fără a schimba aspectul:
 *  - elimină atributele interne ale editorului (data-cms-*);
 *  - imagini responsive (srcset WebP, dimensiuni explicite unde CSS-ul permite, încărcare prioritară pentru
 *    prima imagine, lazy pentru restul);
 *  - imagini de fundal servite în variante pe lățimi (reguli CSS cu media queries);
 *  - iframe-urile (hărți, video) se încarcă leneș;
 *  - un singur <h1> pe pagină: titlurile <h1> suplimentare devin <h2 class="h1"> (aspect identic, vezi CSS).
 */
class HtmlCompiler
{
    private int $images = 0;

    private int $bgCounter = 0;

    /** @var list<string> reguli CSS pentru fundaluri */
    private array $bgCss = [];

    /** @var list<array{href:string, media:string}> imaginea principală de fundal, de preîncărcat */
    private array $preloads = [];

    private array $mediaCache = [];

    private bool $h1Seen = false;

    /** Numărul de blocuri tratate „deasupra liniei de plutire” (imagini eager). */
    private int $blockIndex = 0;

    public function compile(string $html, bool $aboveFold = false): string
    {
        if (trim($html) === '') {
            return '';
        }
        [$doc, $root] = Dom::load($html);
        $xp = new DOMXPath($doc);
        $this->preloadMedia($xp, $root);

        foreach ($xp->query('.//img', $root) as $img) {
            $this->image($img, $aboveFold);
        }
        foreach ($xp->query('.//*[@data-cms-media-bg or contains(@style, "background-image")]', $root) as $el) {
            $this->background($el, $aboveFold);
        }
        foreach ($xp->query('.//iframe', $root) as $iframe) {
            /** @var DOMElement $iframe */
            $iframe->setAttribute('loading', 'lazy');
            if (! $iframe->hasAttribute('title')) {
                $iframe->setAttribute('title', $iframe->getAttribute('aria-label') ?: 'Conținut încorporat');
            }
            $src = $iframe->getAttribute('src');
            if (str_starts_with($src, '//')) {
                $iframe->setAttribute('src', 'https:'.$src);
            }
        }
        foreach (iterator_to_array($xp->query('.//h1', $root)) as $h1) {
            if (! $this->h1Seen) {
                $this->h1Seen = true;

                continue;
            }
            $this->renameTag($h1, 'h2', 'h1');
        }
        foreach ($xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " o_conditional_hidden ")]', $root) as $el) {
            // Vizibilitatea condiționată Odoo fără condiții definite = vizibil (comportamentul JS original).
            Dom::removeClass($el, 'o_conditional_hidden');
        }
        foreach ($xp->query('.//*[@*[starts-with(name(), "data-cms-")]]', $root) as $el) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                if (str_starts_with($attr->name, 'data-cms-')) {
                    $el->removeAttribute($attr->name);
                }
            }
        }
        $this->blockIndex++;

        return Dom::inner($root);
    }

    /** Pagina are deja titlul principal în șablon (ex. articolele): toate <h1> din conținut devin <h2 class="h1">. */
    public function markH1Seen(): void
    {
        $this->h1Seen = true;
    }

    /**
     * Fundal responsive pentru un element din șablon (ex. coperta articolului).
     *
     * @return string clasa CSS de adăugat elementului
     */
    public function backgroundFor(Media $media, bool $preload): string
    {
        $class = 'cmsbg-'.(++$this->bgCounter).'-'.substr(md5($media->path), 0, 6);
        $variants = $media->webpVariants();
        if (! $variants) {
            $this->bgCss[] = ".$class{background-image:url(\"{$media->url()}\")}";

            return $class;
        }
        $pick = function (int $min) use ($variants) {
            foreach ($variants as $w => $u) {
                if ($w >= $min) {
                    return $u;
                }
            }

            return end($variants);
        };
        [$m, $t, $d] = [$pick(768), $pick(1440), $pick(1920)];
        $this->bgCss[] = ".$class{background-image:url(\"$m\")}@media (min-width:768px){.$class{background-image:url(\"$t\")}}@media (min-width:1440px){.$class{background-image:url(\"$d\")}}";
        if ($preload && ! $this->preloads) {
            $this->preloads = [
                ['href' => $m, 'media' => '(max-width: 767.98px)'],
                ['href' => $t, 'media' => '(min-width: 768px) and (max-width: 1439.98px)'],
                ['href' => $d, 'media' => '(min-width: 1440px)'],
            ];
        }

        return $class;
    }

    public function css(): string
    {
        return implode("\n", $this->bgCss);
    }

    public function preloads(): array
    {
        return $this->preloads;
    }

    /** Imaginea principală e preîncărcată pentru toate ecranele (sau nu e nevoie de alta). */
    public function preloadsComplete(): bool
    {
        return isset($this->preloadedRanges['mic'], $this->preloadedRanges['mare']) || ($this->preloads !== [] && $this->preloadedRanges === []);
    }

    private function preloadMedia(DOMXPath $xp, DOMElement $root): void
    {
        $ids = [];
        foreach ($xp->query('.//*[@data-cms-media]/@data-cms-media | .//*[@data-cms-media-bg]/@data-cms-media-bg', $root) as $attr) {
            $ids[] = (int) $attr->value;
        }
        $missing = array_diff(array_unique($ids), array_keys($this->mediaCache));
        if ($missing) {
            foreach (Media::whereIn('id', $missing)->get() as $m) {
                $this->mediaCache[$m->id] = $m;
            }
        }
    }

    private function media(?string $id): ?Media
    {
        return $id ? ($this->mediaCache[(int) $id] ?? null) : null;
    }

    private function image(DOMElement $img, bool $aboveFold): void
    {
        $media = $this->media($img->getAttribute('data-cms-media'));
        $this->images++;
        $eager = $aboveFold && $this->images <= 2;

        if ($media) {
            if ($media->mime === 'image/svg+xml' && ! empty($media->variants['display'])) {
                $img->setAttribute('src', Media::baseUrl().'/'.$media->variants['display']);
            } else {
                $img->setAttribute('src', $media->url());
            }
            if ($srcset = $media->srcset()) {
                $img->setAttribute('srcset', $srcset);
                $img->setAttribute('sizes', $this->sizes($img));
            }
            $classes = ' '.$img->getAttribute('class').' ';
            if (! $img->hasAttribute('width') && $media->width && $media->height && str_contains($classes, ' img-fluid ') && ! str_contains($img->getAttribute('style'), 'height')) {
                $img->setAttribute('width', (string) $media->width);
                $img->setAttribute('height', (string) $media->height);
            }
        }
        $img->setAttribute('loading', $eager ? 'eager' : 'lazy');
        $img->setAttribute('decoding', 'async');
        if ($eager && $this->images === 1) {
            $img->setAttribute('fetchpriority', 'high');
        }
        if (! $img->hasAttribute('alt')) {
            $img->setAttribute('alt', '');
        }
    }

    /** Lățimea afișată estimată din clasele de coloană Bootstrap ale strămoșilor. */
    private function sizes(DOMElement $el): string
    {
        $fraction = 1.0;
        for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if (preg_match('/\bcol-lg-(\d{1,2})\b/', $p->getAttribute('class'), $m)) {
                $fraction *= ((int) $m[1]) / 12;
            }
        }
        $style = $el->getAttribute('style');
        if (preg_match('/width:\s*(\d+)%/', $style, $m)) {
            $fraction *= ((int) $m[1]) / 100;
        }
        if ($fraction >= 0.99) {
            return '100vw';
        }

        return '(min-width: 992px) '.max(160, (int) round(1320 * $fraction)).'px, 100vw';
    }

    private function background(DOMElement $el, bool $aboveFold): void
    {
        $media = $this->media($el->getAttribute('data-cms-media-bg'));
        if (! $media || $media->mime === 'image/svg+xml' || ! $media->webpVariants()) {
            return;
        }
        $variants = $media->webpVariants();
        $class = 'cmsbg-'.(++$this->bgCounter).'-'.substr(md5($media->path), 0, 6);
        Dom::addClass($el, $class);
        $style = preg_replace('/background-image\s*:\s*url\([^)]*\)\s*;?/i', '', $el->getAttribute('style'));
        trim((string) $style) === '' ? $el->removeAttribute('style') : $el->setAttribute('style', trim((string) $style));

        $pick = function (int $min) use ($variants) {
            foreach ($variants as $w => $u) {
                if ($w >= $min) {
                    return $u;
                }
            }

            return end($variants);
        };
        $mobile = $pick(768);
        $tablet = $pick(1440);
        $desktop = $pick(1920);
        $this->bgCss[] = ".$class{background-image:url(\"$mobile\")}";
        if ($tablet !== $mobile) {
            $this->bgCss[] = "@media (min-width:768px){.$class{background-image:url(\"$tablet\")}}";
        }
        if ($desktop !== $tablet) {
            $this->bgCss[] = "@media (min-width:1440px){.$class{background-image:url(\"$desktop\")}}";
        }
        if ($aboveFold) {
            $this->preloadBackground($el, $mobile, $tablet, $desktop);
        }
    }

    /** Intervalele de ecran (sub / peste 992 px) care au deja imaginea principală preîncărcată. */
    private array $preloadedRanges = [];

    /**
     * Preîncarcă imaginea principală doar pentru ecranele pe care secțiunea e vizibilă: copertele Odoo au o variantă
     * pentru telefon (d-lg-none) și una pentru desktop (d-none d-lg-flex), fiecare cu altă imagine.
     */
    private function preloadBackground(DOMElement $el, string $mobile, string $tablet, string $desktop): void
    {
        $section = $el;
        while ($section instanceof DOMElement && $section->nodeName !== 'section') {
            $section = $section->parentNode;
        }
        $classes = ' '.($section instanceof DOMElement ? $section->getAttribute('class') : '').' ';
        $mobileOnly = str_contains($classes, ' d-lg-none ') || str_contains($classes, ' o_snippet_desktop_invisible ');
        $desktopOnly = str_contains($classes, ' o_snippet_mobile_invisible ') || (str_contains($classes, ' d-none ') && preg_match('/ d-lg-(flex|block) /', $classes));

        // Aceleași praguri ca regulile CSS de mai sus, deci se descarcă o singură variantă pe ecran.
        if (! $desktopOnly && ! isset($this->preloadedRanges['mic'])) {
            $this->preloadedRanges['mic'] = true;
            $this->preloads[] = ['href' => $mobile, 'media' => '(max-width: 767.98px)'];
            $this->preloads[] = ['href' => $tablet, 'media' => '(min-width: 768px) and (max-width: 991.98px)'];
        }
        if (! $mobileOnly && ! isset($this->preloadedRanges['mare'])) {
            $this->preloadedRanges['mare'] = true;
            $this->preloads[] = ['href' => $tablet, 'media' => '(min-width: 992px) and (max-width: 1439.98px)'];
            $this->preloads[] = ['href' => $desktop, 'media' => '(min-width: 1440px)'];
        }
    }

    private function renameTag(DOMElement $el, string $tag, string $extraClass): void
    {
        $new = $el->ownerDocument->createElement($tag);
        foreach ($el->attributes as $attr) {
            $new->setAttribute($attr->name, $attr->value);
        }
        Dom::addClass($new, $extraClass);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
    }
}
