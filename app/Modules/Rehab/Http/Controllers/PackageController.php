<?php

namespace App\Modules\Rehab\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Service;
use App\Modules\Patients\Models\Patient;
use App\Modules\Rehab\Models\PackageDefinition;
use App\Modules\Rehab\Models\PatientPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pachete de ședințe: doar dacă clinica le configurează. Tarifele nu se gestionează aici (modulul financiar).
 */
class PackageController extends Controller
{
    public function index(): View
    {
        return view('rehab::packages.index', [
            'definitions' => PackageDefinition::orderBy('name')->get(),
            'services' => Service::active()->whereIn('kind', ['sedinta', 'procedura'])->pluck('name', 'id')->all(),
            'editing' => request()->filled('edit') ? PackageDefinition::find(request()->integer('edit')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $definition = PackageDefinition::create($data + ['created_by' => $request->user()->id]);
        Audit::log('rehab.package_definition_created', $definition);

        return redirect()->route('panou.rehab.packages.index')->with('status', 'Tipul de pachet a fost adăugat.');
    }

    public function update(Request $request, PackageDefinition $definition): RedirectResponse
    {
        $definition->update($this->validated($request));
        Audit::log('rehab.package_definition_updated', $definition);

        return redirect()->route('panou.rehab.packages.index')->with('status', 'Tipul de pachet a fost actualizat. Pachetele deja atribuite își păstrează numărul de ședințe.');
    }

    public function assign(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate([
            'rehab_package_definition_id' => ['required', 'integer', 'exists:rehab_package_definitions,id'],
            'valid_from' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $definition = PackageDefinition::where('is_active', true)->findOrFail($data['rehab_package_definition_id']);
        $from = \Carbon\Carbon::parse($data['valid_from']);
        $package = PatientPackage::create([
            'patient_id' => $patient->id,
            'rehab_package_definition_id' => $definition->id,
            'sessions_total' => $definition->sessions_count,
            'valid_from' => $from->toDateString(),
            'valid_until' => $definition->validity_days ? $from->copy()->addDays($definition->validity_days - 1)->toDateString() : null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('rehab.package_assigned', $package, ['tip' => $definition->id], $patient->id);

        return redirect()->route('panou.patients.show', [$patient, 'tab' => 'recuperare'])->with('status', 'Pachetul a fost atribuit pacientului.');
    }

    public function cancel(Request $request, PatientPackage $package): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $package->update(['status' => 'anulat', 'notes' => trim(($package->notes ? $package->notes.' · ' : '').'Anulat: '.$request->input('reason'))]);
        Audit::log('rehab.package_cancelled', $package, [], $package->patient_id);

        return back()->with('status', 'Pachetul a fost anulat. Ședințele deja consumate rămân înregistrate.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:200'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['service_ids.required' => 'Alegeți cel puțin un serviciu inclus.']);
        $data['service_ids'] = array_map('intval', $data['service_ids']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
