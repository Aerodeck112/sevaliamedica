<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Site\Services\ConsentConfig;
use App\Modules\Site\Services\PageCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Etichetele de măsurare și marketing (Google tag, Meta Pixel). Se încarcă pe site doar după consimțământul categoriei
 * lor și nu pe paginile excluse. Permisiune separată: cms.tracking.
 */
class TrackingController extends Controller
{
    public function edit(): View
    {
        return view('cms::settings.tracking', ['tags' => ConsentConfig::tags()]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'tags' => ['array', 'max:10'],
            'tags.*.type' => ['nullable', Rule::in(array_keys(ConsentConfig::TAG_TYPES))],
            'tags.*.id' => ['nullable', 'string', 'max:40'],
            'tags.*.category' => ['nullable', Rule::in(array_keys(ConsentConfig::CATEGORIES))],
            'tags.*.active' => ['boolean'],
            'tags.*.excluded' => ['nullable', 'string', 'max:2000'],
        ]);

        $tags = [];
        foreach ((array) ($data['tags'] ?? []) as $i => $tag) {
            if (empty($tag['type']) || empty($tag['id'])) {
                continue;
            }
            $id = strtoupper(trim($tag['id']));
            if ($tag['type'] === 'meta') {
                $id = trim($tag['id']);
            }
            if (! ConsentConfig::validId($tag['type'], $id)) {
                throw ValidationException::withMessages(["tags.$i.id" => 'Identificatorul nu are formatul așteptat (ex. AW-123456789, G-ABC123XYZ sau un ID numeric Meta).']);
            }
            $excluded = array_values(array_filter(array_map(fn ($p) => '/'.trim(trim($p), '/'), preg_split('/[\r\n,]+/', (string) ($tag['excluded'] ?? ''))), fn ($p) => $p !== '/'));
            $tags[] = [
                'type' => $tag['type'],
                'id' => $id,
                'category' => $tag['category'] ?? 'marketing',
                'active' => (bool) ($tag['active'] ?? false),
                'excluded' => $excluded,
            ];
        }
        $settings->set('site.tracking', $tags);
        PageCache::flush();
        Audit::log('cms.tracking.updated', context: ['etichete' => array_map(fn ($t) => $t['type'].':'.$t['id'].($t['active'] ? ':activ' : ':inactiv'), $tags)]);

        return back()->with('status', 'Etichetele au fost salvate.');
    }
}
