<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientRepresentative;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RepresentativeController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validateWithBag('representative', [
            'name' => ['required', 'string', 'max:160'],
            'relationship' => ['required', Rule::in(array_keys(PatientRepresentative::RELATIONSHIPS))],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'document_type' => ['nullable', 'string', 'max:80'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $rep = $patient->representatives()->create($data);
        Audit::log('patients.representative_added', $rep, ['calitate' => $rep->relationship], $patient->id);

        return redirect()->route('panou.patients.show', $patient)->with('status', 'Reprezentantul a fost adăugat.');
    }

    public function destroy(Patient $patient, PatientRepresentative $representative): RedirectResponse
    {
        abort_unless($representative->patient_id === $patient->id, 404);
        // Nu ștergem istoric: reprezentantul devine inactiv de azi.
        $representative->update(['valid_until' => now()->subDay()->toDateString()]);
        Audit::log('patients.representative_ended', $representative, patientId: $patient->id);

        return back()->with('status', 'Calitatea de reprezentant a fost încheiată.');
    }
}
