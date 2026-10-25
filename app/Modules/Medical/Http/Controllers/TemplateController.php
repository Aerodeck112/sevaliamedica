<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Medical\Models\MedicalTemplateVersion;
use App\Modules\Medical\Support\TemplateSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Șabloanele de fișă se definesc de medici, pe specialitățile confirmate ale clinicii. Aplicația nu propune
 * câmpuri medicale: editorul oferă doar tipuri de câmpuri.
 */
class TemplateController extends Controller
{
    public function index(): View
    {
        return view('medical::templates.index', [
            'templates' => MedicalTemplate::with(['specialty', 'latestVersion'])->orderBy('name')->get(),
            'usage' => MedicalRecord::query()->join('medical_template_versions as v', 'v.id', '=', 'medical_records.template_version_id')
                ->selectRaw('v.medical_template_id as tid, count(*) as n')->groupBy('v.medical_template_id')->pluck('n', 'tid'),
        ]);
    }

    public function create(): View
    {
        return view('medical::templates.create', ['specialties' => Specialty::active()->pluck('name', 'id')->all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $template = MedicalTemplate::create($data + [
            'draft_schema' => ['sections' => []],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        Audit::log('medical.template_created', $template);

        return redirect()->route('panou.medical.templates.edit', $template)->with('status', 'Șablonul a fost creat. Adăugați secțiunile și câmpurile, apoi publicați o versiune.');
    }

    public function edit(MedicalTemplate $template): View
    {
        $template->load(['versions.publisher', 'specialty']);
        try {
            $preview = TemplateSchema::normalize($template->draft_schema ?? []);
        } catch (ValidationException) {
            $preview = null;
        }

        return view('medical::templates.edit', [
            'template' => $template,
            'schema' => $template->draft_schema ?? ['sections' => []],
            'preview' => $preview,
            'types' => TemplateSchema::TYPES,
            'specialties' => Specialty::active()->pluck('name', 'id')->all(),
        ]);
    }

    public function update(Request $request, MedicalTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'sections' => ['nullable', 'array'],
        ]);
        $sections = collect($data['sections'] ?? [])
            ->sortBy(fn ($s) => (int) ($s['order'] ?? 999))
            ->map(function ($s) {
                $s['fields'] = collect($s['fields'] ?? [])->sortBy(fn ($f) => (int) ($f['order'] ?? 999))->values()->all();

                return $s;
            })
            ->filter(fn ($s) => trim((string) ($s['title'] ?? '')) !== '' && empty($s['remove']))
            ->map(function ($s) {
                $s['fields'] = array_values(array_filter($s['fields'], fn ($f) => empty($f['remove'])));

                return $s;
            })
            ->values()->all();

        try {
            $schema = TemplateSchema::normalize(['sections' => $sections]);
        } catch (ValidationException $e) {
            // Păstrăm și ce s-a introdus, ca utilizatorul să nu piardă munca.
            $template->forceFill(['draft_schema' => ['sections' => $sections]])->save();
            throw $e;
        }
        $template->update([
            'name' => $data['name'],
            'specialty_id' => $data['specialty_id'] ?? null,
            'description' => $data['description'] ?? null,
            'draft_schema' => $schema,
            'updated_by' => $request->user()->id,
        ]);
        Audit::log('medical.template_updated', $template);

        return back()->with('status', 'Structura a fost salvată ca draft. Fișele noi o vor folosi după „Publică versiunea”.');
    }

    public function publish(Request $request, MedicalTemplate $template): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $schema = TemplateSchema::normalize($template->draft_schema ?? []);
        $version = DB::transaction(function () use ($template, $schema, $data, $request) {
            $next = (int) MedicalTemplateVersion::where('medical_template_id', $template->id)->lockForUpdate()->max('version') + 1;

            return MedicalTemplateVersion::create([
                'medical_template_id' => $template->id,
                'version' => $next,
                'schema' => $schema,
                'notes' => $data['notes'] ?? null,
                'published_by' => $request->user()->id,
                'published_at' => now(),
            ]);
        });
        Audit::log('medical.template_published', $template, ['versiune' => $version->version]);

        return back()->with('status', "Versiunea {$version->version} a fost publicată. Fișele existente rămân pe versiunea cu care au fost create.");
    }

    public function toggle(MedicalTemplate $template): RedirectResponse
    {
        $template->update(['is_active' => ! $template->is_active]);
        Audit::log('medical.template_toggled', $template, ['activ' => $template->is_active]);

        return back()->with('status', $template->is_active ? 'Șablonul este activ.' : 'Șablonul a fost dezactivat (fișele existente nu sunt afectate).');
    }
}
