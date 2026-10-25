<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientMergeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MergeController extends Controller
{
    public function create(Request $request, Patient $patient, PatientMergeService $merge): View
    {
        abort_if($patient->merged_into_id !== null, 404);
        $target = $request->filled('target_id') ? Patient::active()->find($request->integer('target_id')) : null;

        return view('patients::merge', [
            'source' => $patient,
            'target' => $target,
            'candidates' => $request->filled('q') ? Patient::active()->search($request->query('q'))->where('id', '!=', $patient->id)->limit(10)->get() : collect(),
            'preview' => $merge->preview($patient),
        ]);
    }

    public function store(Request $request, Patient $patient, PatientMergeService $merge): RedirectResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer', 'exists:patients,id'],
            'reason' => ['required', 'string', 'max:500'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirmați că ați verificat că fișele aparțin aceleiași persoane.']);

        $target = Patient::findOrFail($data['target_id']);
        $merge->merge($patient, $target, $request->user(), $data['reason']);

        return redirect()->route('panou.patients.show', $target)->with('status', 'Fișele au fost unite. Fișa #'.$patient->id.' rămâne în istoric, marcată ca unită.');
    }
}
