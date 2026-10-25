<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\DuplicateDetector;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** „Solicitări noi”: identificarea pacientului, propunerea intervalului, confirmarea. */
class RequestController extends Controller
{
    public function index(Request $request): View
    {
        $requests = Appointment::where('status', AppointmentStatus::SOLICITARE)
            ->with(['service', 'staffMember', 'location', 'requestSpecialty', 'patient'])
            ->orderBy('created_at')->paginate(30);
        $unidentified = Appointment::where('status', AppointmentStatus::CONFIRMATA)->whereNull('patient_id')
            ->where('starts_at', '>=', now()->startOfDay())->with(['service', 'staffMember', 'location'])->orderBy('starts_at')->get();
        $reschedule = Appointment::where('status', AppointmentStatus::CONFIRMATA)->whereNotNull('reschedule_requested_at')
            ->with(['patient', 'service', 'staffMember'])->orderBy('starts_at')->get();

        return view('scheduling::requests.index', compact('requests', 'unidentified', 'reschedule'));
    }

    public function show(Request $request, Appointment $appointment, DuplicateDetector $detector, AvailabilityService $availability, BookingService $booking): View
    {
        abort_unless($appointment->status === AppointmentStatus::SOLICITARE || $appointment->patient_id === null, 404);
        $contact = $appointment->request_contact ?? [];
        Audit::log('scheduling.request_viewed', $appointment, patientId: $appointment->patient_id);

        [$last, $first] = $this->splitName($contact['name'] ?? '');
        $candidates = $appointment->patient_id ? collect() : collect([
            ...$detector->check(['phone' => $contact['phone'] ?? null, 'last_name' => $last, 'first_name' => $first, 'birth_date' => $contact['birth_date'] ?? null])['warnings']->pluck('patient'),
            ...($last ? Patient::active()->search($contact['name'])->limit(5)->get() : []),
        ])->unique('id')->values();

        $suggestions = [];
        $serviceId = $appointment->service_id ?? Service::active()->where('specialty_id', $appointment->request_specialty_id)->value('id');
        $locationId = $appointment->location_id ?? Location::active()->value('id');
        if ($serviceId && $locationId) {
            $from = $appointment->request_preferred_start && $appointment->request_preferred_start->isFuture() ? $appointment->request_preferred_start : CarbonImmutable::now();
            $suggestions = $availability->suggest($booking, new BookingRequest(0, (int) $serviceId, $appointment->staff_member_id, (int) $locationId, $from));
        }

        return view('scheduling::requests.show', [
            'req' => $appointment->load(['service', 'staffMember', 'location', 'requestSpecialty', 'patient', 'events.user']),
            'contact' => $contact,
            'candidates' => $candidates,
            'suggestions' => $suggestions,
            'prefillName' => [$last, $first],
            'services' => Service::active()->get(),
            'staff' => StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])->get(),
            'locations' => Location::active()->pluck('name', 'id')->all(),
            'defaultServiceId' => $serviceId,
            'defaultLocationId' => $locationId,
        ]);
    }

    public function attachPatient(Request $request, Appointment $appointment, BookingService $booking): RedirectResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('merged_into_id')]]);
        $appointment->forceFill(['patient_id' => $data['patient_id'], 'updated_by' => $request->user()->id])->save();
        $booking->event($appointment, $request->user(), 'pacient_identificat', null, null, null, ['pacient' => (int) $data['patient_id']]);
        Audit::log('scheduling.patient_identified', $appointment, [], (int) $data['patient_id']);

        return redirect()->route($appointment->status === AppointmentStatus::SOLICITARE ? 'panou.requests.show' : 'panou.appointments.show', $appointment)
            ->with('status', 'Pacientul a fost identificat.');
    }

    public function confirm(Request $request, Appointment $appointment, BookingService $booking): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'payer_type' => ['nullable', Rule::in(array_keys(Appointment::PAYER_TYPES))],
        ]);
        try {
            $confirmed = $booking->confirmRequest($appointment, new BookingRequest(
                (int) $appointment->patient_id, (int) $data['service_id'], $data['staff_member_id'] ?? null, (int) $data['location_id'],
                CarbonImmutable::parse($data['date'].' '.$data['time']), payerType: $data['payer_type'] ?? null,
            ), $request->user());
        } catch (BookingConflictException $e) {
            return back()->withInput()->withErrors(['time' => implode(' ', $e->messages())]);
        }

        return redirect()->route('panou.appointments.show', $confirmed)->with('status', 'Solicitarea a fost confirmată ca programare. Anunțați pacientul.');
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return [$parts[0] ?? '', ''];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }
}
