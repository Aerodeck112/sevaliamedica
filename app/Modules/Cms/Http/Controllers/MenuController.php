<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Services\Blocks\Slots;
use App\Modules\Cms\Services\Html\StructureSanitizer;
use App\Modules\Cms\Services\LinkChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Meniul principal al siteului. Meniurile extinse („mega”) păstrează conținutul original și se editează pe sloturi
 * (texte și linkuri); meniurile simple și listele derulante se editează ca elemente.
 */
class MenuController extends Controller
{
    public function index(LinkChecker $links): View
    {
        return view('cms::menus.index', [
            'items' => MenuItem::where('menu', 'header')->whereNull('parent_id')->orderBy('sort')->with('children')->get(),
            'broken' => $links->brokenInMenus(),
        ]);
    }

    public function create(): View
    {
        return view('cms::menus.form', ['item' => new MenuItem(['kind' => 'link', 'is_visible' => true]), 'parents' => $this->parents(), 'slots' => [], 'media' => collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['menu'] = 'header';
        $data['sort'] = (int) MenuItem::where('menu', 'header')->where('parent_id', $data['parent_id'] ?? null)->max('sort') + 1;
        if ($data['kind'] === 'mega') {
            $data['mega_block'] = ['uid' => 'menu', 'type' => 'html', 'label' => 'Meniu extins', 'snippet' => 's_mega_menu_little_icons', 'hidden' => false,
                'html' => Slots::annotate('<section class="s_mega_menu_little_icons overflow-hidden o_colored_level o_cc o_cc1"><div class="container"><div class="row"><div class="col-12 col-sm py-2 d-flex align-items-center o_colored_level"><nav class="nav flex-column"><a href="/" class="nav-link px-2 rounded text-wrap"><b>Element nou</b></a></nav></div></div></div></section>')];
        }
        $item = MenuItem::create($data);
        Audit::log('cms.menu.created', $item, ['eticheta' => $item->label]);

        return redirect()->route('panou.cms.menus.edit', $item)->with('status', 'Elementul de meniu a fost adăugat.');
    }

    public function edit(MenuItem $item): View
    {
        $slots = $item->kind === 'mega' && $item->mega_block ? Slots::extract($item->mega_block['html'] ?? '') : [];
        $ids = collect($slots)->pluck('value.media_id')->filter();

        return view('cms::menus.form', ['item' => $item, 'parents' => $this->parents($item), 'slots' => $slots, 'media' => Media::whereIn('id', $ids)->get()->keyBy('id')]);
    }

    public function update(Request $request, MenuItem $item): RedirectResponse
    {
        $data = $this->validated($request, $item);
        $values = $request->filled('slots_json') ? (json_decode((string) $request->input('slots_json'), true)['menu'] ?? null) : $request->input('slots.menu');
        if ($item->kind === 'mega' && $item->mega_block && is_array($values)) {
            $block = $item->mega_block;
            $block['html'] = Slots::apply($block['html'], $values);
            $data['mega_block'] = $block;
        }
        $item->update($data);
        Audit::log('cms.menu.updated', $item, ['eticheta' => $item->label]);

        return back()->with('status', 'Meniul a fost actualizat și este vizibil imediat pe site.');
    }

    public function move(Request $request, MenuItem $item): RedirectResponse
    {
        $siblings = MenuItem::where('menu', $item->menu)->where('parent_id', $item->parent_id)->orderBy('sort')->get()->values();
        $index = $siblings->search(fn ($s) => $s->id === $item->id);
        $swap = $request->input('dir') === 'up' ? $index - 1 : $index + 1;
        if ($swap >= 0 && $swap < $siblings->count()) {
            $ordered = $siblings->all();
            [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
            foreach ($ordered as $i => $s) {
                $s->update(['sort' => $i + 1]);
            }
            Audit::log('cms.menu.moved', $item);
        }

        return back();
    }

    public function destroy(MenuItem $item): RedirectResponse
    {
        Audit::log('cms.menu.deleted', $item, ['eticheta' => $item->label]);
        $item->delete();

        return redirect()->route('panou.cms.menus.index')->with('status', 'Elementul a fost eliminat din meniu.');
    }

    private function validated(Request $request, ?MenuItem $item = null): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(array_keys(MenuItem::KINDS))],
            'url' => ['nullable', 'string', 'max:500'],
            'parent_id' => ['nullable', 'integer', Rule::exists('cms_menu_items', 'id')->whereNull('parent_id')],
            'new_tab' => ['boolean'],
            'is_visible' => ['boolean'],
        ]);
        if ($data['kind'] === 'link' && (empty($data['url']) || ! StructureSanitizer::safeUrl($data['url']))) {
            abort(back()->withInput()->withErrors(['url' => 'Completați o adresă validă (ex. /contactus sau https://…).']));
        }
        if ($item && ($data['parent_id'] ?? null) === $item->id) {
            $data['parent_id'] = null;
        }
        $data['new_tab'] = (bool) ($data['new_tab'] ?? false);
        $data['is_visible'] = (bool) ($data['is_visible'] ?? false);

        return $data;
    }

    private function parents(?MenuItem $except = null): array
    {
        return MenuItem::where('menu', 'header')->whereNull('parent_id')->where('kind', 'dropdown')
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->pluck('label', 'id')->all();
    }
}
