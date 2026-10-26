<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Medical\Models\MedicalDocument;
use App\Modules\Medical\Models\PatientConsent;
use App\Modules\Medical\Services\MedicalAccess;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Consemnarea consimțământului pacientului pentru o versiune exactă a textului clinicii.
 * Aplicația nu preia semnături: consimțământul e semnat pe hârtie (și scanat) sau consemnat de personal.
 */
class ConsentController extends Controller
{
    public function __construct(private readonly MedicalAccess $access) {}

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $this->access->authorize($request->user(), $patient, 'medical.consents');
        $data = $request->validate([
            'consent_template_version_id' => ['required', 'integer', 'exists:consent_template_versions,id'],
            'method' => ['required', Rule::in(array_keys(PatientConsent::METHODS))],
            'given_by' => ['required', Rule::in(array_keys(PatientConsent::GIVEN_BY))],
            'representative_name' => ['nullable', 'required_if:given_by,reprezentant', 'string', 'max:160'],
            'given_at' => ['required', 'date', 'before_or_equal:now'],
            'medical_document_id' => ['nullable', 'required_if:method,hartie_scanat', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'medical_document_id.required_if' => 'Pentru un consimțământ semnat pe hârtie, încărcați întâi scanarea (tabul Documente, categoria „Consimțământ semnat”) și selectați-o.',
            'representative_name.required_if' => 'Indicați numele reprezentantului legal.',
        ]);
        $version = ConsentTemplateVersion::findOrFail($data['consent_template_version_id']);
        abort_if(trim((string) $version->body) === '', 422, 'Modelul nu are text publicat.');
        if (! empty($data['medical_document_id'])) {
            abort_unless(MedicalDocument::whereKey($data['medical_document_id'])->where('patient_id', $patient->id)->active()->exists(), 422, 'Documentul nu aparține pacientului.');
        }

        $consent = PatientConsent::create($data + ['patient_id' => $patient->id, 'collected_by' => $request->user()->id]);
        Audit::log('medical.consent_recorded', $consent, ['model' => $version->consent_template_id, 'versiune' => $version->version, 'mod' => $consent->method], $patient->id);

        return redirect()->route('panou.patients.show', [$patient, 'tab' => 'consimtaminte'])->with('status', 'Consimțământul a fost consemnat.');
    }

    public function show(Request $request, PatientConsent $consent): View
    {
        $this->access->authorize($request->user(), $consent->patient);
        $consent->load(['templateVersion.template', 'collector', 'document', 'patient']);
        Audit::log('medical.consent_viewed', $consent, [], $consent->patient_id);

        return view('medical::consents.show', ['consent' => $consent]);
    }

    public function withdraw(Request $request, PatientConsent $consent): RedirectResponse
    {
        $this->access->authorize($request->user(), $consent->patient, 'medical.consents');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        if ($consent->withdrawn_at) {
            return back()->with('warning', 'Consimțământul este deja retras.');
        }
        $consent->forceFill(['withdrawn_at' => now(), 'withdrawn_by' => $request->user()->id, 'withdraw_reason' => $data['reason']])->save();
        Audit::log('medical.consent_withdrawn', $consent, [], $consent->patient_id);

        return back()->with('status', 'Retragerea consimțământului a fost consemnată.');
    }
}
