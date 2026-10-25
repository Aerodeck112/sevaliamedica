<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\AppointmentSeries;
use App\Modules\Scheduling\Services\SeriesScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SeriesController extends Controller
{
    public function __construct(private readonly SeriesScheduler $scheduler) {}

    public function index(Request $request): View
    {
        $series = AppointmentSeries::with(['patient', 'service', 'staffMember'])
            ->when($request->query('status', 'activa') !== 'toate', fn ($q) => $q->where('status', $request->query('status', 'activa')))
            ->latest()->paginate(30)->withQueryString();

        return view('scheduling::series.index', ['series' => $series]);
    }

    public function create(Request $request): View
    {
        return view('scheduling::series.create', [
            'patient' => $request->filled('patient_id') ? Patient::active()->find($request->integer('patient_id')) : null,
            ...$this->options(),
        ]);
    }

    public function plan(Request $request): View
    {
        $data = $this->validated($request);
        $plan = $this->scheduler->plan((int) $data['patient_id'], (int) $data['service_id'], $data['staff_member_id'] ?? null, (int) $data['location_id'], $this->rule($data));

        return view('scheduling::series.plan', [
            'data' => $data,
            'plan' => $plan,
            'patient' => Patient::findOrFail($data['patient_id']),
            'service' => Service::findOrFail($data['service_id']),
            'staffMember' => isset($data['staff_member_id']) ? StaffMember::find($data['staff_member_id']) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $request->validate([
            'choice' => ['required', 'array'],
            'choice.*.start' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(\|\d*)?$/'],
            'allow_partial' => ['sometimes', 'boolean'],
        ]);
        $choices = [];
        foreach ($request->input('choice') as $index => $c) {
            // Alternativele vin ca „Y-m-d H:i|idPersoană”.
            [$start, $staff] = array_pad(explode('|', $c['start'], 2), 2, null);
            $choices[] = ['index' => (int) $index, 'start' => $start, 'skip' => empty($c['include']), 'staff_member_id' => $staff ?: null];
        }
        $result = $this->scheduler->create((int) $data['patient_id'], (int) $data['service_id'], $data['staff_member_id'] ?? null, (int) $data['location_id'],
            $this->rule($data), $choices, $request->user(), $request->boolean('allow_partial'), $data['notes'] ?? null);

        $msg = count($result['created']).' ședințe programate.';
        if ($result['failed']) {
            $msg .= ' '.count($result['failed']).' nerezervate — vedeți seria pentru a le completa.';
        }

        return redirect()->route('panou.series.show', $result['series'])->with('status', $msg);
    }

    public function show(AppointmentSeries $series): View
    {
        return view('scheduling::series.show', [
            'series' => $series->load(['patient', 'service', 'staffMember', 'location', 'appointments.staffMember']),
            'counts' => $series->counts(),
            'staff' => StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])->pluck('display_name', 'id')->all(),
        ]);
    }

    public function planReschedule(Request $request, AppointmentSeries $series): View
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'time' => ['required', 'date_format:H:i'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $plan = $this->scheduler->planReschedule($series, ['start_date' => $data['start_date'], 'weekdays' => $data['weekdays'], 'time' => $data['time'], 'count' => 1], $data['staff_member_id'] ?? null);

        return view('scheduling::series.reschedule', ['series' => $series->load(['patient', 'service']), 'plan' => $plan, 'data' => $data]);
    }

    public function reschedule(Request $request, AppointmentSeries $series): RedirectResponse
    {
        $data = $request->validate([
            'choice' => ['required', 'array'],
            'choice.*.start' => ['required', 'date'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $choices = [];
        foreach ($data['choice'] as $appointmentId => $c) {
            $choices[] = ['appointment_id' => (int) $appointmentId, 'start' => $c['start'], 'skip' => empty($c['include'])];
        }
        $result = $this->scheduler->applyReschedule($series, $choices, $data['staff_member_id'] ?? null, $request->user(), $data['reason']);

        return redirect()->route('panou.series.show', $series)
            ->with('status', $result['moved'].' ședințe reprogramate.')
            ->with('warning', $result['failed'] ? count($result['failed']).' ședințe nu au putut fi mutate (intervalul s-a ocupat între timp).' : null);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('merged_into_id')],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'time' => ['required', 'date_format:H:i'],
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['weekdays.required' => 'Alegeți cel puțin o zi a săptămânii.']);
    }

    private function rule(array $data): array
    {
        return ['start_date' => $data['start_date'], 'weekdays' => array_map('intval', $data['weekdays']), 'time' => $data['time'], 'count' => (int) $data['count']];
    }

    private function options(): array
    {
        return [
            'services' => Service::active()->get(),
            'staff' => StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])->pluck('display_name', 'id')->all(),
            'locations' => Location::active()->pluck('name', 'id')->all(),
        ];
    }
}
