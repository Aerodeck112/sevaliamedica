<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Medical\Services\MedicalAccess;
use App\Modules\Medical\Services\RecordService;
use App\Modules\Medical\Support\TemplateSchema;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RecordController extends Controller
{
    public function __construct(private readonly MedicalAccess $access, private readonly RecordService $records) {}

    /** Drafturile proprii, de finalizat. */
    public function drafts(Request $request): View
    {
        $staff = $this->access->staffFor($request->user());
        $drafts = $staff ? MedicalRecord::where('staff_member_id', $staff->id)->where('status', MedicalRecord::DRAFT)
            ->with(['patient', 'amends'])->orderBy('created_at')->get() : collect();

        return view('medical::records.drafts', ['drafts' => $drafts, 'staff' => $staff]);
    }

    public function create(Request $request, Patient $patient): View
    {
        $this->access->authorize($request->user(), $patient, 'medical.write');

        return view('medical::records.create', [
            'patient' => $patient,
            'templates' => MedicalTemplate::usable()->with(['specialty', 'latestVersion'])->orderBy('name')->get(),
            'appointments' => Appointment::where('patient_id', $patient->id)->whereNotNull('starts_at')
                ->where('starts_at', '>=', now()->subDays(30))->where('starts_at', '<=', now()->addDay())
                ->whereNotIn('status', ['anulata', 'solicitare'])->with('service')->orderByDesc('starts_at')->get(),
            'selected' => [
                'template_id' => $request->integer('template') ?: null,
                'appointment_id' => $request->integer('appointment') ?: null,
                'rehab_plan_id' => $request->integer('plan') ?: null,
                'kind' => $request->query('kind', 'consultatie'),
            ],
        ]);
    }

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:medical_templates,id'],
            'appointment_id' => ['nullable', 'integer'],
            'rehab_plan_id' => ['nullable', 'integer', 'exists:rehab_plans,id'],
            'kind' => ['required', Rule::in(['consultatie', 'reevaluare', 'nota'])],
            'title' => ['nullable', 'string', 'max:190'],
        ]);
        if (! empty($data['rehab_plan_id'])) {
            abort_unless(DB::table('rehab_plans')->where('id', $data['rehab_plan_id'])->where('patient_id', $patient->id)->exists(), 422);
        }
        $template = ! empty($data['template_id']) ? MedicalTemplate::with('latestVersion')->find($data['template_id']) : null;
        $record = $this->records->createDraft($patient, $template, $request->user(), $data);

        return redirect()->route('panou.medical.records.edit', $record)->with('status', 'Draftul a fost creat. Completați și salvați; finalizarea se face la final.');
    }

    public function show(Request $request, MedicalRecord $record): View
    {
        $reason = $this->access->authorize($request->user(), $record->patient);
        $record->load(['patient', 'staffMember', 'templateVersion.template', 'finalizer', 'appointment.service', 'amends', 'documents']);
        $root = $record->root()->load(['amendments.staffMember', 'amendments.finalizer', 'staffMember']);
        Audit::log('medical.record_viewed', $record, ['acces' => $reason], $record->patient_id);

        return view('medical::records.show', [
            'record' => $record,
            'root' => $root,
            'chain' => collect([$root])->merge($root->amendments->where('status', '!=', MedicalRecord::ABANDONED)),
            'current' => $root->latestRectification() ?? $root,
            'canAmend' => $request->user()->can('medical.amend') && $root->isFinal(),
            'canEdit' => $record->isDraft() && $this->isAuthor($request, $record),
            'emergency' => $reason === 'urgenta',
        ]);
    }

    public function edit(Request $request, MedicalRecord $record): View|RedirectResponse
    {
        if (! $record->isDraft()) {
            return redirect()->route('panou.medical.records.show', $record);
        }
        $this->records->assertEditableBy($record, $request->user());
        $record->load(['patient', 'templateVersion.template', 'amends', 'appointment.service']);
        Audit::log('medical.record_opened', $record, [], $record->patient_id);

        return view('medical::records.edit', [
            'record' => $record,
            'schema' => $record->schema(),
            'values' => $record->content ?? [],
            'missing' => TemplateSchema::missingRequired($record->schema(), $record->content ?? []),
        ]);
    }

    public function update(Request $request, MedicalRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:190'],
            'values' => ['nullable', 'array'],
        ]);
        $this->records->saveDraft($record, $data['values'] ?? [], (int) $data['version'], $request->user(), $data['title'] ?? null);

        return redirect()->route('panou.medical.records.edit', $record)->with('status', 'Draftul a fost salvat.');
    }

    public function finalize(Request $request, MedicalRecord $record): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirmați că ați verificat conținutul.']);
        $this->records->finalize($record, $request->user());

        return redirect()->route('panou.medical.records.show', $record)->with('status', 'Fișa a fost finalizată. Conținutul nu mai poate fi modificat; corecturile se fac prin completare sau rectificare.');
    }

    public function amendForm(Request $request, MedicalRecord $record): View
    {
        $root = $record->root();
        $this->access->authorize($request->user(), $root->patient, 'medical.amend');

        return view('medical::records.amend', ['record' => $root->load('patient')]);
    }

    public function amend(Request $request, MedicalRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['completare', 'rectificare'])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $amendment = $this->records->amend($record, $data['kind'], $data['reason'], $request->user());

        return redirect()->route('panou.medical.records.edit', $amendment)->with('status', 'Corectura a fost pornită ca draft. Originalul rămâne neschimbat.');
    }

    public function abandon(Request $request, MedicalRecord $record): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->records->abandon($record, $data['reason'], $request->user());

        return redirect()->route('panou.patients.show', [$record->patient_id, 'tab' => 'fise'])->with('status', 'Draftul a fost abandonat (rămâne în jurnal).');
    }

    private function isAuthor(Request $request, MedicalRecord $record): bool
    {
        $staff = $this->access->staffFor($request->user());

        return $staff && (int) $staff->id === (int) $record->staff_member_id;
    }
}
