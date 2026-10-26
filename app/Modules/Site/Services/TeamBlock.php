<?php

namespace App\Modules\Site\Services;

use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Services\Html\Dom;
use App\Modules\Cms\Services\Import\TeamMatcher;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;

/**
 * Blocul „echipă” (s_company_team): markupul original se păstrează, iar numele, titulatura, fotografia și
 * vizibilitatea fiecărui medic vin din fișele modulului Clinică (câmpurile show_on_site, display_name,
 * professional_title, photo_media_id). Persoanele noi, afișate pe site, se adaugă la finalul grilei.
 *
 * Două moduri:
 *  - „all” (secțiunile cu toată echipa: prima pagină, /echipa-noastra): numele, titulatura și fotografia vin din fișe,
 *    iar persoanele noi se adaugă automat;
 *  - „selected” (secțiunile cu medicii unei specialități, ex. /medicina-interna-…): textele specifice paginii rămân
 *    neschimbate; se ascund doar persoanele care nu mai sunt afișate pe site.
 * Implicit, o secțiune cu cel puțin 5 persoane recunoscute este „all”; se poate fixa prin config.mode.
 */
class TeamBlock
{
    private static ?Collection $staff = null;

    public static function render(string $html, ?string $mode = null): string
    {
        $staff = self::staff();
        [$doc, $root] = Dom::load($html);
        $xp = new DOMXPath($doc);
        $grid = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " o_grid_mode ")]', $root)->item(0)
            ?? $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " row ")]', $root)->item(0);
        if (! $grid instanceof DOMElement) {
            return $html;
        }
        $items = iterator_to_array($xp->query('./*[contains(concat(" ", normalize-space(@class), " "), " o_grid_item ")]', $grid));
        if ($mode === null) {
            $recognized = 0;
            foreach ($items as $item) {
                $h = $xp->query('.//h4', $item)->item(0);
                $recognized += $h && self::match($staff, Dom::text($h)) ? 1 : 0;
            }
            $mode = $recognized >= 5 ? 'all' : 'selected';
        }
        $shown = [];
        $template = null;
        $maxRow = 0;
        foreach ($items as $item) {
            /** @var DOMElement $item */
            [$row] = self::gridArea($item);
            $maxRow = max($maxRow, $row);
            $h4 = $xp->query('.//h4', $item)->item(0);
            $member = $h4 ? self::match($staff, Dom::text($h4)) : null;
            if (! $member) {
                continue; // element fără corespondent în fișe: rămâne exact ca în original
            }
            $template ??= $item;
            if (! $member->is_active || ! $member->show_on_site) {
                $item->parentNode->removeChild($item);

                continue;
            }
            if ($mode === 'all') {
                self::fill($xp, $item, $member);
            }
            $shown[$member->id] = true;
        }

        $template ??= $items[0] ?? null;
        if ($mode === 'all' && $template instanceof DOMElement) {
            $new = $staff->filter(fn (StaffMember $m) => ! isset($shown[$m->id]) && $m->show_on_site && $m->is_active)->values();
            foreach ($new as $i => $member) {
                /** @var DOMElement $clone */
                $clone = $template->cloneNode(true);
                $start = $maxRow + 1 + intdiv($i, 2) * 6;
                [$c1, $c2] = $i % 2 === 0 ? [1, 7] : [7, 13];
                $style = trim((string) preg_replace('/grid-area:[^;]+;?/', '', $clone->getAttribute('style')), " ;");
                $clone->setAttribute('style', ($style !== '' ? $style.'; ' : '')."grid-area: $start / $c1 / ".($start + 5)." / $c2;");
                self::fill($xp, $clone, $member);
                $grid->appendChild($clone);
            }
            if ($new->isNotEmpty()) {
                $grid->setAttribute('data-row-count', (string) ($maxRow + (int) ceil($new->count() / 2) * 6));
            }
        }

        return Dom::inner($root);
    }

    public static function flushCache(): void
    {
        self::$staff = null;
    }

    private static function staff(): Collection
    {
        return self::$staff ??= StaffMember::orderBy('site_sort')->orderBy('id')->get();
    }

    private static function match(Collection $staff, string $name): ?StaffMember
    {
        $key = TeamMatcher::key($name);

        return $staff->first(fn (StaffMember $s) => TeamMatcher::key($s->display_name) === $key);
    }

    /** @return array{0:int} rândul de final din grid-area */
    private static function gridArea(DOMElement $item): array
    {
        if (preg_match('/grid-area:\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)/', $item->getAttribute('style'), $m)) {
            return [(int) $m[3]];
        }

        return [0];
    }

    private static function fill(DOMXPath $xp, DOMElement $item, StaffMember $member): void
    {
        if ($h4 = $xp->query('.//h4', $item)->item(0)) {
            self::setDeepText($h4, $member->display_name);
        }
        if (($h5 = $xp->query('.//h5', $item)->item(0)) && $member->professional_title) {
            self::setDeepText($h5, $member->professional_title);
        }
        $img = $xp->query('.//img', $item)->item(0);
        if ($img instanceof DOMElement && $member->photo_media_id && ($media = Media::find($member->photo_media_id))) {
            $img->setAttribute('data-cms-media', (string) $media->id);
            $img->setAttribute('src', $media->url());
            $img->setAttribute('alt', $member->display_name);
        } elseif ($img instanceof DOMElement && $img->getAttribute('alt') === '') {
            $img->setAttribute('alt', $member->display_name);
        }
    }

    /** Înlocuiește textul în cel mai adânc element care îl conține, păstrând formatarea (strong, span). */
    private static function setDeepText(DOMElement $el, string $text): void
    {
        $target = $el;
        while (true) {
            $children = array_values(array_filter(iterator_to_array($target->childNodes), fn ($n) => $n instanceof DOMElement || trim((string) $n->textContent) !== ''));
            if (count($children) === 1 && $children[0] instanceof DOMElement && strtolower($children[0]->tagName) !== 'br') {
                $target = $children[0];

                continue;
            }
            break;
        }
        if (Dom::text($target) === trim($text)) {
            return;
        }
        $target->textContent = $text;
    }
}
