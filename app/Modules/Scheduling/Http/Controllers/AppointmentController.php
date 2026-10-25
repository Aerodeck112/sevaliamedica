<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Equipment;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Room;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\WaitlistEntry;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Services\PatientLinkService;
use App\Modules\Scheduling\Services\WaitlistService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly BookingService $booking, private readonly AvailabilityService $availability) {}

    public function create(Request $request): View
    {
        $location = Location::find($request->integer('location_id')) ?? Location::active()->first();

        return view('scheduling::appointments.form', [
            'patient' => $request->filled('patient_id') ? Patient::active()->find($request->integer('patient_id')) : null,
            'prefill' => [
                'date' => $request->query('date', now()->toDateString()),
                'time' => $request->query('time', ''),
                'staff_member_id' => $request->query('staff_member_id'),
                'service_id' => $request->query('service_id'),
                'location_id' => $location?->id,
                'waitlist_entry_id' => $request->query('waitlist_entry_id'),
            ],
            ...$this->formOptions($location),
        ]);
    }

    public function store(Request $request, WaitlistService $waitlist): RedirectResponse|View
    {
        $data = $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('merged_into_id')],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:600'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'payer_type' => ['nullable', Rule::in(array_keys(Appointment::PAYER_TYPES))],
            'source' => ['nullable', Rule::in(['receptie', 'telefon'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'override' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'required_if:override,1', 'string', 'max:255'],
            'waitlist_entry_id' => ['nullable', 'integer', 'exists:waitlist_entries,id'],
        ], ['override_reason.required_if' => 'Indicați motivul programării în afara programului.']);

        $override = (bool) ($data['override'] ?? false);
        abort_if($override && ! $request->user()->can('scheduling.override'), 403);

        $start = CarbonImmutable::parse($data['date'].' '.$data['time']);
        $bookingRequest = new BookingRequest(
            patientId: (int) $data['patient_id'],
            serviceId: (int) $data['service_id'],
            staffMemberId: $data['staff_member_id'] ?? null,
            locationId: (int) $data['location_id'],
            startsAt: $start,
            endsAt: ! empty($data['duration']) ? $start->addMinutes((int) $data['duration']) : null,
            roomIds: array_filter([$data['room_id'] ?? null]),
            equipmentIds: array_filter([$data['equipment_id'] ?? null]),
            source: $data['source'] ?? 'receptie',
            payerType: $data['payer_type'] ?? null,
            notes: $data['notes'] ?? null,
            overrideSchedule: $override,
            overrideReason: $data['override_reason'] ?? null,
        );

        try {
            $appointment = $this->booking->book($bookingRequest, $request->user());
        } catch (BookingConflictException $e) {
            $location = Location::find($data['location_id']);

            return view('scheduling::appointments.form', [
                'patient' => Patient::find($data['patient_id']),
                'prefill' => $data,
                'conflicts' => $e->messages(),
                'suggestions' => $this->availability->suggest($this->booking, $bookingRequest->with(['roomIds' => [], 'equipmentIds' => []])),
                ...$this->formOptions($location),
            ]);
        }

        if (! empty($data['waitlist_entry_id']) && ($entry = WaitlistEntry::find($data['waitlist_entry_id']))) {
            $waitlist->markBooked($entry, $appointment);
        }

        $warnings = $appointment->getRelation('bookingWarnings') ?? collect();

        return redirect()->route('panou.appointments.show', $appointment)
            ->with('status', 'Programarea a fost creată: '.$appointment->starts_at->format('d.m.Y H:i').'.')
            ->with('warning', $warnings->isNotEmpty() ? $warnings->join(' ') : null);
    }

    public function show(Request $request, Appointment $appointment, WaitlistService $waitlist): View
    {
        $this->authorize('view', $appointment);
        $appointment->load(['patient', 'service', 'staffMember', 'location', 'series', 'activeResources', 'events.user', 'serviceRecords.service', 'serviceRecords.staffMember']);
        Audit::log('scheduling.appointment_viewed', $appointment, patientId: $appointment->patient_id);

        $user = $request->user();
        $canManage = $user->can('scheduling.manage');
        $suggestions = [];
        if ($canManage && in_array($appointment->status, [AppointmentStatus::CONFIRMATA, AppointmentStatus::SOSIT], true) && $appointment->service_id && $appointment->patient_id) {
            $suggestions = $this->availability->suggest($this->booking, new BookingRequest(
                (int) $appointment->patient_id, (int) $appointment->service_id, $appointment->staff_member_id, (int) $appointment->location_id,
                CarbonImmutable::now(), CarbonImmutable::now()->addMinutes($appointment->durationMinutes()),
            ), $appointment->id);
        }

        return view('scheduling::appointments.show', [
            'appointment' => $appointment,
            'transitions' => collect($appointment->allowedTransitions())->filter(fn ($to) => $user->can('transition', [$appointment, $to]))->values(),
            'canManage' => $canManage,
            'canRecord' => $user->can('recordService', $appointment),
            'suggestions' => $suggestions,
            'waitlistMatches' => $appointment->status === AppointmentStatus::ANULATA && $user->can('waitlist.manage') && $appointment->starts_at?->isFuture()
                ? $waitlist->matchesFor($appointment) : collect(),
            'staffOptions' => StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])->pluck('display_name', 'id')->all(),
            'services' => Service::active()->pluck('name', 'id')->all(),
            'newLink' => session('patient_link'),
            'annotations' => app(\App\Modules\Scheduling\Support\AppointmentAnnotations::class)->for(collect([$appointment]), $user)[$appointment->id] ?? [],
        ]);
    }

    public function status(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in(array_keys(AppointmentStatus::LABELS))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->authorize('transition', [$appointment, $data['to']]);
        $this->booking->changeStatus($appointment, $data['to'], $request->user(), $data['reason'] ?? null);

        $redirect = $request->input('return') === 'calendar'
            ? redirect()->back()
            : redirect()->route('panou.appointments.show', $appointment);

        return $redirect->with('status', 'Status: '.AppointmentStatus::label($data['to']).'.');
    }

    /** Mutare (calendar drag-and-drop → JSON; formularul „Mută programarea” → redirect). */
    public function move(Request $request, Appointment $appointment): JsonResponse|RedirectResponse
    {
        $this->authorize('manage', $appointment);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:600'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'version' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'override' => ['sometimes', 'boolean'],
        ], ['reason.required' => 'Indicați motivul modificării.']);
        $override = (bool) ($data['override'] ?? false);
        abort_if($override && ! $request->user()->can('scheduling.override'), 403);

        $start = CarbonImmutable::parse($data['date'].' '.$data['time']);
        $end = ! empty($data['duration']) ? $start->addMinutes((int) $data['duration']) : null;

        try {
            $moved = $this->booking->move($appointment, $start, $end, $data['staff_member_id'] ?? null, isset($data['version']) ? (int) $data['version'] : null,
                $request->user(), $data['reason'], ! empty($data['room_id']) ? [(int) $data['room_id']] : null, null, $override);
        } catch (BookingConflictException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'conflicts' => $e->messages()], 409);
            }

            return back()->withInput()->withErrors(['time' => implode(' ', $e->messages())]);
        }

        $warnings = ($moved->getRelation('bookingWarnings') ?? collect())->all();
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'version' => $moved->version, 'message' => 'Programarea a fost mutată la '.$moved->starts_at->format('d.m.Y H:i').'.', 'warnings' => $warnings]);
        }

        return redirect()->route('panou.appointments.show', $moved)->with('status', 'Programarea a fost mutată la '.$moved->starts_at->format('d.m.Y H:i').'.')
            ->with('warning', $warnings ? implode(' ', $warnings) : null);
    }

    /** Intervale libere (JSON) pentru formularul de programare. */
    public function suggestions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'staff_member_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        ]);
        $from = CarbonImmutable::parse($data['date'] ?? 'now');
        $from = $from->isToday() || $from->isPast() ? CarbonImmutable::now() : $from->startOfDay();
        $items = $this->availability->suggest($this->booking, new BookingRequest(0, (int) $data['service_id'], $data['staff_member_id'] ?? null, (int) $data['location_id'], $from));

        return response()->json(array_map(fn ($s) => [
            'date' => $s['start']->format('Y-m-d'), 'time' => $s['start']->format('H:i'),
            'label' => $s['start']->locale('ro')->isoFormat('ddd D MMM, HH:mm'), 'staff_member_id' => $s['staff_member_id'], 'staff' => $s['staff'],
        ], $items));
    }

    public function issueLink(Request $request, Appointment $appointment, PatientLinkService $links): RedirectResponse
    {
        $this->authorize('manage', $appointment);
        abort_unless($appointment->status === AppointmentStatus::CONFIRMATA && $appointment->starts_at?->isFuture(), 422, 'Linkul se emite doar pentru programări confirmate, viitoare.');
        $link = $links->issue($appointment, $request->user());
        Audit::log('scheduling.patient_link_issued', $appointment, ['expira' => $link['expires_at']->toIso8601String()], $appointment->patient_id);

        return back()->with('patient_link', $link['url'])->with('status', 'Linkul a fost generat. Este afișat o singură dată.');
    }

    /** Leagă o programare confirmată automat (pacient neidentificat) de o fișă. */
    public function attachPatient(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('manage', $appointment);
        $data = $request->validate(['patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('merged_into_id')]]);
        abort_if($appointment->patient_id !== null, 422, 'Programarea are deja un pacient.');
        $appointment->forceFill(['patient_id' => $data['patient_id'], 'updated_by' => $request->user()->id])->save();
        $this->booking->event($appointment, $request->user(), 'pacient_identificat', null, null, null, ['pacient' => (int) $data['patient_id']]);
        Audit::log('scheduling.patient_identified', $appointment, [], (int) $data['patient_id']);

        return back()->with('status', 'Pacientul a fost asociat programării.');
    }

    private function formOptions(?Location $location): array
    {
        return [
            'locations' => Location::active()->pluck('name', 'id')->all(),
            'services' => Service::active()->get(),
            'staff' => StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])
                ->when($location, fn ($q) => $q->whereHas('locations', fn ($l) => $l->where('locations.id', $location->id)))
                ->get(),
            'rooms' => $location ? Room::where('location_id', $location->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            'equipment' => $location ? Equipment::where('location_id', $location->id)->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->name.' ('.$e->kind.')'])->all() : [],
        ];
    }
}
