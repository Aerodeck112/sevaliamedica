<?php

namespace App\Modules\Site\Services;

use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Cms\Services\Html\Dom;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Randarea publică a unei versiuni de pagină: blocuri → HTML final + CSS pentru fundaluri + preîncărcări.
 */
class Renderer
{
    public function __construct(private readonly FormBlock $forms) {}

    /**
     * @return array{main:string, css:string, preloads:array, blocks:int}
     */
    public function render(Page $page, PageVersion $version, ?Request $request = null, bool $preview = false): array
    {
        $compiler = new HtmlCompiler;
        $out = '';
        $visible = array_values(array_filter($version->blocks, fn ($b) => empty($b['hidden'])));
        foreach ($visible as $i => $block) {
            $html = $this->blockHtml($page, $block, $request, $preview);
            $out .= $compiler->compile($html, $i === 0)."\n";
        }

        return ['main' => $out, 'css' => $compiler->css(), 'preloads' => $compiler->preloads(), 'blocks' => count($visible)];
    }

    /** Randează un singur bloc (folosit și pentru zonele globale, ex. subsolul). */
    public function renderBlocks(array $blocks, ?Request $request = null): array
    {
        $compiler = new HtmlCompiler;
        $out = '';
        foreach ($blocks as $block) {
            if (empty($block['hidden'])) {
                $out .= $compiler->compile($this->blockHtml(null, $block, $request, false), false)."\n";
            }
        }

        return ['main' => $out, 'css' => $compiler->css()];
    }

    private function blockHtml(?Page $page, array $block, ?Request $request, bool $preview): string
    {
        return match ($block['type'] ?? 'html') {
            'team' => TeamBlock::render($block['html'] ?? ''),
            'blog_latest' => $this->blogLatest($block),
            'blog_index' => view('site::blog.index', $this->blogIndexData($request, (int) ($block['config']['per_page'] ?? 12)))->render(),
            'form' => $this->forms->render($block, $preview),
            default => (string) ($block['html'] ?? ''),
        };
    }

    private function blogLatest(array $block): string
    {
        $count = (int) ($block['config']['count'] ?? 3);
        $articles = Page::articles()->published()->with('cover')->orderByDesc('published_at')->limit($count)->get();
        [$doc, $root] = Dom::load($block['html'] ?? '');
        $xp = new DOMXPath($doc);
        $target = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dynamic_snippet_template ")]', $root)->item(0);
        if ($target instanceof \DOMElement) {
            Dom::setInner($target, view('site::blog.latest-cards', ['articles' => $articles])->render());
        }

        return Dom::inner($root);
    }

    public function blogIndexData(?Request $request, int $perPage): array
    {
        $search = trim((string) $request?->query('search'));
        $query = Page::articles()->published()->with(['cover', 'publishedVersion'])->orderByDesc('published_at');
        if ($search !== '') {
            $query->where('title', 'like', '%'.addcslashes($search, '%_').'%');
        }
        $pageNumber = max(1, (int) ($request?->route('number') ?? 1));
        $articles = $query->paginate($perPage, ['*'], 'page', $pageNumber);

        return ['articles' => $articles, 'search' => $search, 'teaser' => fn (Page $a) => self::teaser($a)];
    }

    /** Rezumatul afișat în listă: primele ~200 de caractere din text, ca în Odoo. */
    public static function teaser(Page $article): string
    {
        $text = '';
        foreach ($article->publishedVersion?->blocks ?? [] as $block) {
            $text .= ' '.strip_tags(str_replace(['<br>', '</p>', '</h2>', '</h5>'], ' ', (string) ($block['html'] ?? '')));
            if (mb_strlen($text) > 400) {
                break;
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(str_replace("\u{00A0}", ' ', $text))));

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200).'...' : $text;
    }

    public static function shortDate(?\DateTimeInterface $date): string
    {
        if (! $date) {
            return '';
        }
        $months = [1 => 'ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.', 'iul.', 'aug.', 'sept.', 'oct.', 'nov.', 'dec.'];
        $d = \Carbon\Carbon::instance($date)->timezone(config('app.timezone'));

        return $d->day.' '.$months[$d->month].' '.$d->year;
    }

    public static function longDate(?\DateTimeInterface $date): string
    {
        if (! $date) {
            return '';
        }
        $months = [1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
        $d = \Carbon\Carbon::instance($date)->timezone(config('app.timezone'));

        return $d->day.' '.$months[$d->month].' '.$d->year;
    }

    public static function slugTitle(string $text): string
    {
        return Str::limit($text, 70);
    }
}
