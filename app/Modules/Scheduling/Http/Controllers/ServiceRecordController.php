<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Services\ServiceRecordService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceRecordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ServiceRecordService $records) {}

    public function storeFromAppointment(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('recordService', $appointment);
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_member_id' => ['required', 'integer', 'exists:staff_members,id'],
            'payer_type' => ['required', Rule::in(array_keys(ServiceRecord::PAYER_TYPES))],
            'insurer_name' => ['nullable', 'required_if:payer_type,asigurator', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->records->fromAppointment($appointment, $data, $request->user());

        return back()->with('status', 'Serviciul efectuat a fost înregistrat.');
    }

    public function storeManual(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_member_id' => ['required', 'integer', 'exists:staff_members,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'performed_at' => ['required', 'date', 'before_or_equal:now'],
            'payer_type' => ['required', Rule::in(array_keys(ServiceRecord::PAYER_TYPES))],
            'insurer_name' => ['nullable', 'required_if:payer_type,asigurator', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'manual_reason' => ['required', 'string', 'min:5', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->records->manual($patient, $data, $request->user());

        return redirect()->route('panou.patients.show', [$patient, 'tab' => 'servicii'])->with('status', 'Serviciul efectuat a fost înregistrat.');
    }

    public function cancel(Request $request, ServiceRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:5', 'max:500'],
            'replace' => ['sometimes', 'boolean'],
            'replacement_service_id' => ['nullable', 'required_if:replace,1', 'integer', 'exists:services,id'],
            'replacement_payer_type' => ['nullable', Rule::in(array_keys(ServiceRecord::PAYER_TYPES))],
            'replacement_quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $replacement = ! empty($data['replace']) ? [
            'service_id' => $data['replacement_service_id'] ?? null,
            'payer_type' => $data['replacement_payer_type'] ?? null,
            'quantity' => $data['replacement_quantity'] ?? null,
        ] : null;
        $new = $this->records->cancel($record, $data['cancel_reason'], $request->user(), $replacement);

        return back()->with('status', $new ? 'Înregistrarea a fost corectată (cea veche rămâne în istoric, marcată anulată).' : 'Înregistrarea a fost anulată (rămâne în istoric).');
    }
}
