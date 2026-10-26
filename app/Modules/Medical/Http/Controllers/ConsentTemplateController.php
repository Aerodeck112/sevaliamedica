<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Modelele de consimțământ: textul îl furnizează și îl validează clinica; aplicația doar îl versionează. */
class ConsentTemplateController extends Controller
{
    public function index(): View
    {
        return view('medical::consent-templates.index', [
            'templates' => ConsentTemplate::with('latestVersion')->withCount('versions')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'scope' => ['nullable', 'string', 'max:190']]);
        $template = ConsentTemplate::create($data + ['created_by' => $request->user()->id]);
        Audit::log('medical.consent_template_created', $template);

        return redirect()->route('panou.medical.consent-templates.edit', $template);
    }

    public function edit(ConsentTemplate $template): View
    {
        return view('medical::consent-templates.edit', ['template' => $template->load('versions')]);
    }

    public function publish(Request $request, ConsentTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'scope' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'min:20', 'max:50000'],
        ]);
        $template->update(['name' => $data['name'], 'scope' => $data['scope'] ?? null]);
        $latest = $template->latestVersion;
        if ($latest && $latest->body === $data['body']) {
            return back()->with('status', 'Denumirea a fost salvată; textul nu s-a schimbat, deci nu s-a creat o versiune nouă.');
        }
        $version = DB::transaction(function () use ($template, $data, $request) {
            $next = (int) ConsentTemplateVersion::where('consent_template_id', $template->id)->lockForUpdate()->max('version') + 1;

            return ConsentTemplateVersion::create([
                'consent_template_id' => $template->id,
                'version' => $next,
                'body' => $data['body'],
                'published_by' => $request->user()->id,
                'published_at' => now(),
            ]);
        });
        Audit::log('medical.consent_template_published', $template, ['versiune' => $version->version]);

        return back()->with('status', "Versiunea {$version->version} a textului a fost publicată. Consimțămintele deja consemnate rămân legate de versiunea lor.");
    }

    public function toggle(ConsentTemplate $template): RedirectResponse
    {
        $template->update(['is_active' => ! $template->is_active]);
        Audit::log('medical.consent_template_toggled', $template, ['activ' => $template->is_active]);

        return back();
    }
}
