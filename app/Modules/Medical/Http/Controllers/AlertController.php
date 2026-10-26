<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Medical\Models\ClinicalAlert;
use App\Modules\Medical\Services\MedicalAccess;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Avertizări introduse de clinician. Aplicația nu generează avertizări și nu face recomandări. */
class AlertController extends Controller
{
    public function __construct(private readonly MedicalAccess $access) {}

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $this->access->authorize($request->user(), $patient, 'medical.write');
        $data = $request->validate([
            'severity' => ['required', Rule::in(array_keys(ClinicalAlert::SEVERITIES))],
            'text' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $alert = ClinicalAlert::create([
            'patient_id' => $patient->id,
            'severity' => $data['severity'],
            'text' => trim($data['text']),
            'staff_member_id' => $this->access->staffFor($request->user())?->id,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('medical.alert_created', $alert, ['severitate' => $alert->severity], $patient->id);

        return redirect()->route('panou.patients.show', [$patient, 'tab' => 'fise'])->with('status', 'Avertizarea a fost adăugată; apare în fișă și în calendar pentru personalul care îngrijește pacientul.');
    }

    public function end(Request $request, ClinicalAlert $alert): RedirectResponse
    {
        $this->access->authorize($request->user(), $alert->patient, 'medical.write');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        if ($alert->ended_at) {
            return back()->with('warning', 'Avertizarea este deja încheiată.');
        }
        $alert->forceFill(['ended_at' => now(), 'ended_by' => $request->user()->id, 'end_reason' => $data['reason']])->save();
        Audit::log('medical.alert_ended', $alert, [], $alert->patient_id);

        return back()->with('status', 'Avertizarea a fost încheiată.');
    }
}
