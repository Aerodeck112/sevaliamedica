<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\CareAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BreakGlassController extends Controller
{
    public function create(Patient $patient): View
    {
        return view('patients::break-glass', ['patient' => $patient]);
    }

    public function store(Request $request, Patient $patient, CareAccess $care): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $access = $care->grantBreakGlass($request->user(), $patient, $request->input('reason'));

        return redirect()->route('panou.patients.show', $patient)
            ->with('warning', 'Acces de urgență activ până la '.$access->expires_at->format('H:i').'. Accesul este înregistrat și va fi verificat.');
    }
}
