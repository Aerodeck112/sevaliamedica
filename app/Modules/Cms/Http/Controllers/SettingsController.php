<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Site\Http\Controllers\PageController as SitePageController;
use App\Modules\Site\Services\ConsentConfig;
use App\Modules\Site\Services\PageCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public const COOKIE_TEXT_KEYS = ['title', 'body', 'accept', 'reject', 'customize', 'save', 'necessary', 'statistici', 'marketing', 'policy_url'];

    public function edit(Settings $settings): View
    {
        return view('cms::settings.edit', [
            'birthdate' => (bool) $settings->get('site.contact_birthdate', false),
            'enRedirect' => (bool) $settings->get('site.en_redirect', true),
            'languageSelector' => (bool) $settings->get('site.language_selector', true),
            'anatomy' => (bool) $settings->get('site.anatomy', true),
            'bodyMap' => (bool) $settings->get('site.body_map', true),
            'bodyMapAfter' => (int) $settings->get('site.body_map_after', 3),
            'ctaBar' => (bool) $settings->get('site.cta_bar', true),
            'social' => array_merge(SitePageController::SOCIAL_DEFAULTS, (array) $settings->get('site.social', [])),
            'cookie' => ConsentConfig::texts(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $rules = [
            'contact_birthdate' => ['boolean'],
            'en_redirect' => ['boolean'],
            'language_selector' => ['boolean'],
            'anatomy' => ['boolean'],
            'body_map' => ['boolean'],
            'body_map_after' => ['nullable', 'integer', 'min:0', 'max:30'],
            'cta_bar' => ['boolean'],
            'social' => ['array'],
            'social.*' => ['nullable', 'url:https', 'max:300'],
            'cookie' => ['array'],
            'cookie.policy_url' => ['nullable', 'string', 'max:200', 'regex:#^(/|https://)#'],
        ];
        foreach (self::COOKIE_TEXT_KEYS as $key) {
            $rules["cookie.$key"] ??= ['nullable', 'string', 'max:600'];
        }
        $data = $request->validate($rules);

        $settings->set('site.contact_birthdate', (bool) ($data['contact_birthdate'] ?? false));
        $settings->set('site.en_redirect', (bool) ($data['en_redirect'] ?? false));
        $settings->set('site.language_selector', (bool) ($data['language_selector'] ?? false));
        $settings->set('site.anatomy', (bool) ($data['anatomy'] ?? false));
        $settings->set('site.body_map', (bool) ($data['body_map'] ?? false));
        $settings->set('site.body_map_after', (int) ($data['body_map_after'] ?? 3));
        $settings->set('site.cta_bar', (bool) ($data['cta_bar'] ?? false));
        $settings->set('site.social', array_intersect_key(array_filter((array) ($data['social'] ?? [])), SitePageController::SOCIAL_DEFAULTS));
        $settings->set('site.cookie', array_map(fn ($v) => strip_tags((string) $v), array_intersect_key(array_filter((array) ($data['cookie'] ?? [])), array_flip(self::COOKIE_TEXT_KEYS))));
        PageCache::flush();
        Audit::log('cms.settings.updated', context: ['data_nasterii' => (bool) ($data['contact_birthdate'] ?? false), 'en_redirect' => (bool) ($data['en_redirect'] ?? false)]);

        return back()->with('status', 'Setările siteului au fost salvate.');
    }
}
