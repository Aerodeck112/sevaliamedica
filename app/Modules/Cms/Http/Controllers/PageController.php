<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Cms\Models\SectionTemplate;
use App\Modules\Cms\Services\Blocks\BlockEditor;
use App\Modules\Cms\Services\Blocks\Slots;
use App\Modules\Cms\Services\EditConflict;
use App\Modules\Cms\Services\LinkChecker;
use App\Modules\Cms\Services\PageService;
use App\Modules\Site\Services\SiteView;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class PageController extends Controller
{
    /** Căi rezervate de aplicație, care nu pot deveni pagini. */
    private const RESERVED = ['/panou', '/media', '/build', '/images', '/formular', '/sitemap.xml', '/robots.txt', '/website', '/up', '/storage'];

    public function __construct(private readonly PageService $pages) {}

    public function index(Request $request, LinkChecker $links): View
    {
        return $this->listing($request, $links, Page::KIND_PAGE);
    }

    public function articles(Request $request, LinkChecker $links): View
    {
        return $this->listing($request, $links, Page::KIND_ARTICLE);
    }

    private function listing(Request $request, LinkChecker $links, string $kind): View
    {
        $q = trim((string) $request->query('q'));
        $pages = Page::where('kind', $kind)->with(['currentVersion', 'editingUser'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', "%$q%")->orWhere('path', 'like', "%$q%")))
            ->orderBy($kind === Page::KIND_ARTICLE ? 'published_at' : 'path', $kind === Page::KIND_ARTICLE ? 'desc' : 'asc')
            ->get();

        $issues = [];
        foreach ($pages as $page) {
            $version = $page->currentVersion;
            $issues[$page->id] = [
                'broken' => $version ? $links->brokenInBlocks($version->blocks) : [],
                'no_description' => empty($version?->seo['description'] ?? null),
            ];
        }

        return view('cms::pages.index', [
            'pages' => $pages, 'issues' => $issues, 'kind' => $kind, 'q' => $q,
            'menuBroken' => $kind === Page::KIND_PAGE ? $links->brokenInMenus() : [],
            'footer' => Page::where('path', Page::FOOTER_PATH)->first(),
        ]);
    }

    public function create(Request $request): View
    {
        $kind = $request->query('tip') === 'articol' ? Page::KIND_ARTICLE : Page::KIND_PAGE;

        return view('cms::pages.create', [
            'kind' => $kind,
            'templates' => SectionTemplate::orderBy('name')->get(),
            'suggestedPath' => $request->query('cale'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = $request->input('kind') === Page::KIND_ARTICLE ? Page::KIND_ARTICLE : Page::KIND_PAGE;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'path' => ['nullable', 'string', 'max:200', 'regex:#^/[a-z0-9\-/]*$#'],
        ], ['path.regex' => 'Adresa poate conține doar litere mici fără diacritice, cifre, „-” și „/”, și începe cu „/”.']);

        $path = $data['path'] ?: ($kind === Page::KIND_ARTICLE
            ? Page::BLOG_PATH.'/'.Str::slug($data['title']).'-'.((int) Page::articles()->count() + 100)
            : '/'.Str::slug($data['title']));
        $path = '/'.trim($path, '/');
        foreach (self::RESERVED as $reserved) {
            if ($path === $reserved || str_starts_with($path, $reserved.'/')) {
                return back()->withInput()->withErrors(['path' => 'Adresa este rezervată de aplicație.']);
            }
        }
        if (Page::where('path', $path)->exists()) {
            return back()->withInput()->withErrors(['path' => 'Există deja o pagină cu această adresă.']);
        }

        $blocks = [];
        if ($kind === Page::KIND_ARTICLE) {
            $blocks[] = ['uid' => BlockEditor::newUid(), 'type' => 'rich', 'label' => 'Text articol', 'snippet' => null, 'hidden' => false, 'html' => '<p>Textul articolului.</p>'];
        }
        $page = Page::create([
            'path' => $path, 'kind' => $kind, 'title' => $data['title'], 'status' => 'draft',
            'created_by' => $request->user()->id, 'author_name' => $kind === Page::KIND_ARTICLE ? $request->user()->name : null,
        ]);
        $this->pages->saveVersion($page, $blocks, ['title' => $data['title'].' | sevalia.ro'], ['body_class' => '', 'wrap_class' => 'oe_structure oe_empty'], $request->user(), null, 'Creare', $data['title']);
        Audit::log('cms.page.created', $page, ['cale' => $path]);

        return redirect()->route('panou.cms.pages.edit', $page)->with('status', 'Pagina a fost creată ca ciornă. Adăugați secțiuni, apoi publicați.');
    }

    public function edit(Request $request, Page $page, LinkChecker $links): View
    {
        $version = $page->currentVersion ?? abort(404);
        $other = $this->pages->acquireLease($page, $request->user());
        Audit::log('cms.page.opened', $page);

        $blocks = [];
        foreach ($version->blocks as $block) {
            $slots = isset($block['html']) && $block['type'] !== 'rich' ? Slots::extract($block['html']) : [];
            if ($block['type'] === 'team') {
                // Numele, titulatura și fotografia medicilor vin din fișele de personal, nu se editează aici.
                $slots = array_values(array_filter($slots, fn ($s) => ! $this->insideGridItem($block['html'], $s['id'])));
            }
            $blocks[] = $block + ['slots' => $slots, 'repeats' => isset($block['html']) ? Slots::repeats($block['html']) : []];
        }
        $mediaIds = [];
        foreach ($blocks as $b) {
            foreach ($b['slots'] as $s) {
                if (in_array($s['kind'], ['image', 'bg'], true) && ! empty($s['value']['media_id'])) {
                    $mediaIds[] = $s['value']['media_id'];
                }
            }
        }

        return view('cms::pages.edit', [
            'page' => $page,
            'version' => $version,
            'blocks' => $blocks,
            'media' => Media::whereIn('id', $mediaIds)->get()->keyBy('id'),
            'templates' => SectionTemplate::orderBy('name')->get(),
            'otherEditor' => $other,
            'broken' => $links->brokenInBlocks($version->blocks),
            'canPublish' => $request->user()->can('cms.publish'),
            'canSeo' => $request->user()->can('cms.seo'),
        ]);
    }

    private function insideGridItem(string $html, string $slotId): bool
    {
        return (bool) preg_match('/o_grid_item[^>]*>(?:(?!o_grid_item).)*data-cms-slot="'.preg_quote($slotId, '/').'"/s', $html);
    }

    public function footer(): RedirectResponse
    {
        $page = Page::where('path', Page::FOOTER_PATH)->firstOrFail();

        return redirect()->route('panou.cms.pages.edit', $page);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $request->validate([
            'lock_version' => ['required', 'integer'],
            'op' => ['nullable', 'string', 'max:120'],
            'slots' => ['nullable', 'array'],
            'rich' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:200'],
            'seo.description' => ['nullable', 'string', 'max:400'],
            'seo.canonical' => ['nullable', 'url', 'max:300'],
            'seo.og_image_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'title' => ['nullable', 'string', 'max:180'],
            'article.subtitle' => ['nullable', 'string', 'max:300'],
            'article.published_at' => ['nullable', 'date'],
            'article.author_name' => ['nullable', 'string', 'max:160'],
            'article.cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
        ]);
        $current = $page->currentVersion ?? abort(404);
        // Editorul cu JavaScript trimite doar sloturile modificate, ca JSON (paginile mari depășesc max_input_vars).
        $slots = (array) $request->input('slots', []);
        if ($request->filled('slots_json')) {
            $decoded = json_decode((string) $request->input('slots_json'), true);
            $slots = is_array($decoded) ? $decoded : [];
        }

        try {
            [$blocks, $note] = BlockEditor::apply($current->blocks, $slots, (array) $request->input('rich', []), $request->input('op'));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['op' => $e->getMessage()]);
        }

        $seo = (array) ($current->seo ?? []);
        if ($request->user()->can('cms.seo') && $request->has('seo')) {
            foreach (['title', 'description', 'canonical', 'og_image_media_id'] as $key) {
                $value = $request->input("seo.$key");
                if ($value === null || $value === '') {
                    unset($seo[$key]);
                } else {
                    $seo[$key] = $key === 'og_image_media_id' ? (int) $value : trim((string) $value);
                }
            }
            $seo['noindex'] = $request->boolean('seo.noindex');
        }
        $settings = (array) ($current->settings ?? []);
        if ($page->kind === Page::KIND_ARTICLE && $request->has('article')) {
            $settings['subtitle'] = (string) $request->input('article.subtitle');
            $page->forceFill([
                'published_at' => $request->filled('article.published_at') ? Carbon::parse($request->input('article.published_at'), config('app.timezone')) : $page->published_at,
                'author_name' => $request->input('article.author_name') ?: null,
                'cover_media_id' => $request->input('article.cover_media_id') ?: null,
            ])->save();
        }

        try {
            $version = $this->pages->saveVersion($page, $blocks, $seo, $settings, $request->user(), (int) $request->input('lock_version'), $note, $request->input('title') ?: $current->title);
        } catch (EditConflict $e) {
            $by = $e->page->updated_by ? \App\Models\User::find($e->page->updated_by)?->name : null;

            return back()->withInput()->withErrors(['lock_version' => 'Pagina a fost salvată între timp'.($by ? ' de '.$by : '').' ('.$e->page->updated_at?->format('H:i').'). Modificările dumneavoastră nu au fost salvate; le găsiți completate mai jos. Reîncărcați pagina pentru a vedea versiunea nouă, apoi reaplicați-le.']);
        }

        if ($request->input('action') === 'publish') {
            abort_unless($request->user()->can('cms.publish'), 403);
            $this->pages->publish($page, $version, $request->user());

            return redirect()->route('panou.cms.pages.edit', $page)->with('status', "Versiunea {$version->number} a fost publicată.");
        }

        $anchor = $request->input('op') ? '#bloc-'.explode(':', (string) $request->input('op'))[1] : '';

        return redirect(route('panou.cms.pages.edit', $page).$anchor)->with('status', "Ciorna a fost salvată (versiunea {$version->number}). {$note}.");
    }

    public function publish(Request $request, Page $page): RedirectResponse
    {
        $version = $request->filled('version') ? PageVersion::where('page_id', $page->id)->findOrFail($request->integer('version')) : $page->currentVersion;
        $this->pages->publish($page, $version, $request->user());

        return back()->with('status', "Versiunea {$version->number} este acum publicată.");
    }

    public function unpublish(Page $page): RedirectResponse
    {
        abort_if($page->kind === Page::KIND_GLOBAL, 422, 'Subsolul nu poate fi retras.');
        $this->pages->unpublish($page);

        return back()->with('warning', 'Pagina a fost retrasă și nu mai este vizibilă public. Dacă avea vizitatori, adăugați un redirect către o pagină potrivită.');
    }

    public function restore(Request $request, Page $page, PageVersion $version): RedirectResponse
    {
        abort_unless($version->page_id === $page->id, 404);
        try {
            $new = $this->pages->restore($page, $version, $request->user(), $page->lock_version);
        } catch (EditConflict) {
            return back()->withErrors(['lock_version' => 'Pagina a fost modificată între timp. Reîncercați.']);
        }

        return redirect()->route('panou.cms.pages.edit', $page)->with('status', "Versiunea {$version->number} a fost restaurată ca ciornă nouă (versiunea {$new->number}). Publicați-o pentru a o face vizibilă.");
    }

    public function versions(Page $page): View
    {
        return view('cms::pages.versions', [
            'page' => $page,
            'versions' => PageVersion::where('page_id', $page->id)->with('author')->orderByDesc('number')->paginate(30),
        ]);
    }

    public function preview(Request $request, Page $page, SiteView $view): Response
    {
        $version = $request->filled('versiune')
            ? PageVersion::where('page_id', $page->id)->where('number', $request->integer('versiune'))->firstOrFail()
            : ($page->currentVersion ?? abort(404));
        if ($page->kind === Page::KIND_GLOBAL) {
            // Subsolul se previzualizează pe prima pagină.
            $home = Page::where('path', '/')->firstOrFail();
            $html = $view->page($home, $home->publishedVersion ?? $home->currentVersion, $request, true)->render();
        } else {
            $html = $view->page($page, $version, $request, true)->render();
        }

        return response($html)->header('X-Robots-Tag', 'noindex');
    }

    public function heartbeat(Request $request, Page $page): JsonResponse
    {
        $other = $this->pages->acquireLease($page, $request->user());

        return response()->json([
            'ok' => $other === null,
            'other' => $other?->name,
            'lock_version' => $page->fresh()->lock_version,
        ]);
    }

    public function release(Request $request, Page $page): JsonResponse
    {
        $this->pages->releaseLease($page, $request->user());

        return response()->json(['ok' => true]);
    }
}
