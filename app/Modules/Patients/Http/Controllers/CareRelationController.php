<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Patients\Models\CareRelation;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CareRelationController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate([
            'staff_member_id' => ['required', 'integer', 'exists:staff_members,id'],
            'source' => ['required', Rule::in(array_keys(CareRelation::SOURCES))],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $relation = $patient->careRelations()->create($data + ['created_by' => $request->user()->id]);
        Audit::log('patients.care_relation_added', $relation, ['personal' => $relation->staff_member_id, 'sursa' => $relation->source], $patient->id);

        return redirect()->route('panou.patients.show', [$patient, 'tab' => 'ingrijire'])->with('status', 'Relația de îngrijire a fost adăugată.');
    }

    public function end(Patient $patient, CareRelation $relation): RedirectResponse
    {
        abort_unless($relation->patient_id === $patient->id, 404);
        $relation->update(['valid_until' => now()->subDay()->toDateString()]);
        Audit::log('patients.care_relation_ended', $relation, patientId: $patient->id);

        return back()->with('status', 'Relația de îngrijire a fost încheiată.');
    }
}
