<?php

namespace App\Modules\Rehab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Medical\Models\ClinicalAlert;
use App\Modules\Rehab\Models\RehabSessionRecord;
use App\Modules\Rehab\Services\SessionProgress;
use App\Modules\Rehab\Services\SessionService;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions, private readonly SessionProgress $progress) {}

    /** Terapeut: ședințele de azi din planuri și cele trecute, nemarcate („de închis”). */
    public function index(Request $request): View
    {
        $staff = StaffMember::where('user_id', $request->user()->id)->first();
        $seriesIds = DB::table('rehab_item_series')->pluck('appointment_series_id');
        $today = $staff && $seriesIds->isNotEmpty() ? Appointment::whereIn('series_id', $seriesIds)->where('staff_member_id', $staff->id)
            ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereNotIn('status', [AppointmentStatus::ANULATA, AppointmentStatus::SOLICITARE])
            ->with(['patient', 'service'])->orderBy('starts_at')->get() : collect();

        return view('rehab::sessions.index', [
            'staff' => $staff,
            'today' => $today,
            'pending' => $staff ? $this->progress->pendingFor($staff->id)->filter(fn ($a) => ! $a->starts_at->isToday()) : collect(),
            'done' => RehabSessionRecord::whereIn('appointment_id', $today->pluck('id'))->whereHas('serviceRecord', fn ($q) => $q->where('status', 'efectuat'))->pluck('appointment_id')->flip(),
        ]);
    }

    public function create(Request $request, Appointment $appointment): View
    {
        $item = $this->sessions->authorize($request->user(), $appointment);
        $appointment->load(['patient', 'service', 'staffMember', 'location']);
        $item->load(['plan.items.service', 'plan.physician']);

        return view('rehab::sessions.form', [
            'appointment' => $appointment,
            'item' => $item,
            'progress' => $this->progress->forItem($item),
            'alerts' => ClinicalAlert::where('patient_id', $appointment->patient_id)->active()->get(),
            'previous' => RehabSessionRecord::where('rehab_plan_item_id', $item->id)->with(['therapist', 'appointment'])->latest('performed_at')->limit(5)->get(),
            'existing' => RehabSessionRecord::where('appointment_id', $appointment->id)->with('serviceRecord')->first(),
            'isPastDay' => $appointment->starts_at && $appointment->starts_at->lt(now()->startOfDay()),
        ]);
    }

    public function store(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate([
            'observations' => ['nullable', 'string', 'max:5000'],
            'evolution' => ['nullable', 'string', 'max:5000'],
            'late_reason' => ['nullable', 'string', 'max:400'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirmați că ședința a avut loc.']);
        $session = $this->sessions->perform($appointment, $request->user(), $data);

        return redirect()->route('panou.rehab.plans.show', $session->item->rehab_plan_id)->with('status', 'Ședința a fost înregistrată ca efectuată.');
    }
}
