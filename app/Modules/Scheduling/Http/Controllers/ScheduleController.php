<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ScheduleException;
use App\Modules\Scheduling\Models\StaffSchedule;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Programul de lucru (șabloane săptămânale), concedii, ore suplimentare și închideri. */
class ScheduleController extends Controller
{
    public function index(): View
    {
        return view('scheduling::schedules.index', [
            'staff' => StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])->withCount('specialties')->get()
                ->map(fn ($s) => (object) ['member' => $s, 'templates' => StaffSchedule::where('staff_member_id', $s->id)->count()]),
            'closures' => ScheduleException::whereNull('staff_member_id')->where('ends_at', '>=', now())->with('location')->orderBy('starts_at')->get(),
            'locations' => Location::active()->pluck('name', 'id')->all(),
        ]);
    }

    public function show(StaffMember $staff): View
    {
        return view('scheduling::schedules.show', [
            'member' => $staff,
            'templates' => StaffSchedule::where('staff_member_id', $staff->id)->with('location')->orderBy('weekday')->orderBy('start_time')->get(),
            'exceptions' => ScheduleException::where('staff_member_id', $staff->id)->where('ends_at', '>=', now()->subDays(30))->orderBy('starts_at')->get(),
            'locations' => $staff->locations()->pluck('name', 'locations.id')->all() ?: Location::active()->pluck('name', 'id')->all(),
        ]);
    }

    public function storeTemplate(Request $request, StaffMember $staff): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'break_from' => ['nullable', 'date_format:H:i', 'after:start_time', 'required_with:break_to'],
            'break_to' => ['nullable', 'date_format:H:i', 'after:break_from', 'before_or_equal:end_time', 'required_with:break_from'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ], ['weekdays.required' => 'Alegeți cel puțin o zi.']);

        foreach ($data['weekdays'] as $day) {
            $overlap = StaffSchedule::where('staff_member_id', $staff->id)->where('weekday', $day)
                ->where('start_time', '<', $data['end_time'].':00')->where('end_time', '>', $data['start_time'].':00')
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $data['valid_from']))
                ->when($data['valid_until'] ?? null, fn ($q, $u) => $q->where('valid_from', '<=', $u))
                ->with('location')->first();
            if ($overlap) {
                return back()->withInput()->withErrors(['start_time' => StaffSchedule::WEEKDAYS[$day].': se suprapune cu programul existent '.substr($overlap->start_time, 0, 5).'–'.substr($overlap->end_time, 0, 5).' ('.$overlap->location?->name.').']);
            }
        }
        foreach ($data['weekdays'] as $day) {
            $row = StaffSchedule::create([
                'staff_member_id' => $staff->id, 'location_id' => $data['location_id'], 'weekday' => (int) $day,
                'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
                'breaks' => ! empty($data['break_from']) ? [[$data['break_from'], $data['break_to']]] : [],
                'valid_from' => $data['valid_from'], 'valid_until' => $data['valid_until'] ?? null,
            ]);
            Audit::log('scheduling.schedule_added', $row, ['zi' => (int) $day]);
        }

        return back()->with('status', 'Programul a fost adăugat.');
    }

    public function destroyTemplate(StaffSchedule $template): RedirectResponse
    {
        $template->delete();
        Audit::log('scheduling.schedule_removed', $template, ['zi' => $template->weekday, 'persoana' => $template->staff_member_id]);

        return back()->with('status', 'Rândul de program a fost șters. Programările existente nu au fost modificate.');
    }

    public function storeException(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'kind' => ['required', Rule::in(array_keys(ScheduleException::KINDS))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if ($data['kind'] === 'inchidere') {
            $data['staff_member_id'] = null;
        } elseif (empty($data['staff_member_id'])) {
            return back()->withInput()->withErrors(['staff_member_id' => 'Alegeți persoana (sau tipul „Închidere” pentru întreaga locație).']);
        }
        $exception = ScheduleException::create($data + ['created_by' => $request->user()->id]);
        Audit::log('scheduling.exception_added', $exception, ['tip' => $exception->kind]);

        // Programările active afectate nu se modifică automat: le afișăm recepției pentru reprogramare.
        $affected = $exception->isUnavailability() ? Appointment::whereIn('status', [AppointmentStatus::CONFIRMATA])
            ->where('starts_at', '<', $exception->ends_at)->where('ends_at', '>', $exception->starts_at)
            ->when($exception->staff_member_id, fn ($q, $s) => $q->where('staff_member_id', $s))
            ->when(! $exception->staff_member_id && $exception->location_id, fn ($q) => $q->where('location_id', $exception->location_id))
            ->count() : 0;

        return back()->with('status', 'Excepția a fost salvată.')
            ->with('warning', $affected ? "Există {$affected} programări confirmate în acest interval. Ele NU au fost mutate automat: verificați-le în calendar și reprogramați-le." : null);
    }

    public function destroyException(ScheduleException $exception): RedirectResponse
    {
        $exception->delete();
        Audit::log('scheduling.exception_removed', $exception, ['tip' => $exception->kind]);

        return back()->with('status', 'Excepția a fost ștearsă.');
    }
}
