<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\WaitlistEntry;
use App\Modules\Scheduling\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WaitlistController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', 'deschise');
        $entries = WaitlistEntry::with(['patient', 'service', 'staffMember', 'location', 'offers'])
            ->when($status === 'deschise', fn ($q) => $q->open(), fn ($q) => $q->where('status', $status))
            ->when($request->integer('service_id'), fn ($q, $s) => $q->where('service_id', $s))
            ->orderBy('priority')->orderBy('created_at')->paginate(30)->withQueryString();

        return view('scheduling::waitlist.index', [
            'entries' => $entries,
            'status' => $status,
            'patient' => $request->filled('patient_id') ? Patient::active()->find($request->integer('patient_id')) : null,
            'freed' => $request->filled('freed') ? Appointment::find($request->integer('freed')) : null,
            'services' => Service::active()->pluck('name', 'id')->all(),
            'staff' => StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])->pluck('display_name', 'id')->all(),
            'locations' => Location::active()->pluck('name', 'id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('merged_into_id')],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'from' => ['nullable', 'date_format:H:i'],
            'to' => ['nullable', 'date_format:H:i', 'after:from'],
            'priority' => ['required', Rule::in(array_keys(WaitlistEntry::PRIORITIES))],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $entry = WaitlistEntry::create([
            'patient_id' => $data['patient_id'], 'service_id' => $data['service_id'] ?? null, 'staff_member_id' => $data['staff_member_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'preferences' => array_filter(['weekdays' => array_map('intval', $data['weekdays'] ?? []), 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]),
            'priority' => (int) $data['priority'], 'valid_until' => $data['valid_until'] ?? null, 'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('scheduling.waitlist_added', $entry, [], $entry->patient_id);

        return redirect()->route('panou.waitlist.index')->with('status', 'Pacientul a fost adăugat în lista de așteptare.');
    }

    public function offer(Request $request, WaitlistEntry $entry, WaitlistService $waitlist): RedirectResponse
    {
        $data = $request->validate([
            'freed_appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'slot_start' => ['required', 'date'],
            'slot_end' => ['required', 'date', 'after:slot_start'],
            'staff_member_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);
        $waitlist->offer($entry, CarbonImmutable::parse($data['slot_start']), CarbonImmutable::parse($data['slot_end']),
            $data['staff_member_id'] ?? null, $data['location_id'] ?? null, $data['freed_appointment_id'] ?? null, $request->user());

        return back()->with('status', 'Oferta a fost înregistrată. Contactați pacientul și, dacă acceptă, creați programarea.');
    }

    public function status(Request $request, WaitlistEntry $entry): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['activ', 'anulat'])],
            'offer_outcome' => ['nullable', Rule::in(['refuzat', 'expirat'])],
        ]);
        if (! empty($data['offer_outcome'])) {
            $entry->offers()->where('outcome', 'in_asteptare')->update(['outcome' => $data['offer_outcome']]);
        }
        $entry->update(['status' => $data['status']]);
        Audit::log('scheduling.waitlist_status', $entry, ['status' => $data['status']], $entry->patient_id);

        return back()->with('status', 'Lista de așteptare a fost actualizată.');
    }
}
