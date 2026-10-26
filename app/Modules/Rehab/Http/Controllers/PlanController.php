<?php

namespace App\Modules\Rehab\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Patients\Models\Patient;
use App\Modules\Rehab\Models\PatientPackage;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Models\RehabPlanItem;
use App\Modules\Rehab\Services\PlanService;
use App\Modules\Rehab\Services\RehabAccess;
use App\Modules\Rehab\Services\SessionProgress;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Modules\Scheduling\Services\SeriesScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly SessionProgress $progress,
        private readonly RehabAccess $access,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(RehabPlan::STATUSES))], 'mine' => ['nullable', 'boolean']]);
        $staff = StaffMember::where('user_id', $request->user()->id)->first();
        $plans = RehabPlan::with(['patient', 'physician', 'items.service', 'location'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', RehabPlan::CLOSED))
            ->when(($filters['mine'] ?? false) && $staff, fn ($q) => $q->where(fn ($w) => $w->where('physician_staff_id', $staff->id)
                ->orWhereHas('items', fn ($i) => $i->where('preferred_therapist_id', $staff->id))))
            ->latest()->paginate(30)->withQueryString();

        return view('rehab::plans.index', ['plans' => $plans, 'filters' => $filters, 'progress' => $this->progress, 'hasStaff' => (bool) $staff]);
    }

    public function create(Request $request, Patient $patient): View
    {
        $this->plans->assertCare($request->user(), $patient, 'rehab.plan');

        return view('rehab::plans.form', [
            'plan' => new RehabPlan(['payer_type' => 'privat', 'medical_record_id' => $request->integer('record') ?: null]),
            'patient' => $patient,
            ...$this->formOptions($patient),
        ]);
    }

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $plan = $this->plans->create($patient, $request->user(), $this->validated($request));

        return redirect()->route('panou.rehab.plans.show', $plan)->with('status', 'Planul a fost creat ca draft. Adăugați procedurile, apoi aprobați-l.');
    }

    public function show(Request $request, RehabPlan $plan): View
    {
        $user = $request->user();
        abort_unless($user->can('rehab.view') || $user->can('rehab.schedule'), 403);
        $plan->load(['patient', 'physician', 'location', 'record', 'items.service.requirements', 'items.preferredTherapist', 'items.series', 'package.definition', 'approver', 'reevaluations.staffMember']);
        $clinical = $this->access->canSeeClinical($user, $plan);
        Audit::log('rehab.plan_viewed', $plan, ['clinic' => $clinical], $plan->patient_id);

        return view('rehab::plans.show', [
            'plan' => $plan,
            'progress' => $this->progress->forPlan($plan),
            'clinical' => $clinical,
            'services' => Service::active()->whereIn('kind', ['sedinta', 'procedura'])->get(),
            'therapists' => StaffMember::active()->whereIn('kind', ['terapeut', 'asistent', 'medic'])->pluck('display_name', 'id')->all(),
            'payers' => ServiceRecord::PAYER_TYPES,
        ]);
    }

    public function edit(Request $request, RehabPlan $plan): View
    {
        $this->plans->assertCare($request->user(), $plan->patient, 'rehab.plan');

        return view('rehab::plans.form', ['plan' => $plan, 'patient' => $plan->patient, ...$this->formOptions($plan->patient)]);
    }

    public function update(Request $request, RehabPlan $plan): RedirectResponse
    {
        $this->plans->updateDraft($plan, $request->user(), $this->validated($request));

        return redirect()->route('panou.rehab.plans.show', $plan)->with('status', 'Planul a fost salvat.');
    }

    public function addItem(Request $request, RehabPlan $plan): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:100'],
            'sessions_per_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'preferred_therapist_id' => ['nullable', 'integer', 'exists:staff_members,id'],
            'payer_type' => ['nullable', Rule::in(array_keys(ServiceRecord::PAYER_TYPES))],
            'instructions' => ['nullable', 'string', 'max:500'],
        ]);
        $this->plans->addItem($plan, $request->user(), $data);

        return back()->with('status', 'Procedura a fost adăugată.');
    }

    public function removeItem(Request $request, RehabPlanItem $item): RedirectResponse
    {
        $this->plans->removeItem($item, $request->user());

        return back()->with('status', 'Procedura a fost eliminată din draft.');
    }

    public function approve(Request $request, RehabPlan $plan): RedirectResponse
    {
        $this->plans->approve($plan, $request->user());

        return back()->with('status', 'Planul a fost aprobat. Ședințele pot fi programate.');
    }

    public function extendItem(Request $request, RehabPlanItem $item): RedirectResponse
    {
        $data = $request->validate(['sessions_count' => ['required', 'integer', 'min:1', 'max:100'], 'reason' => ['required', 'string', 'max:500']]);
        $this->plans->extendItem($item, $request->user(), (int) $data['sessions_count'], $data['reason']);

        return back()->with('status', 'Numărul de ședințe a fost modificat.');
    }

    public function suspend(Request $request, RehabPlan $plan): RedirectResponse
    {
        $this->plans->suspend($plan, $request->user(), (string) $request->input('reason'));

        return back()->with('status', 'Planul a fost suspendat. Ședințele programate rămân în calendar până le reprogramați sau anulați.');
    }

    public function resume(Request $request, RehabPlan $plan): RedirectResponse
    {
        $this->plans->resume($plan, $request->user());

        return back()->with('status', 'Planul a fost reluat.');
    }

    public function close(Request $request, RehabPlan $plan): RedirectResponse
    {
        $data = $request->validate(['conclusion' => ['required', 'string', 'max:5000'], 'cancel_future' => ['sometimes', 'boolean']]);
        $this->plans->close($plan, $request->user(), $data['conclusion'], $request->boolean('cancel_future'));

        return back()->with('status', 'Planul a fost încheiat.');
    }

    /** Pasul 1 al programării unei proceduri: regula (zile, oră, terapeut). */
    public function scheduleForm(Request $request, RehabPlanItem $item): View
    {
        abort_unless($request->user()->can('rehab.schedule'), 403);
        $item->load(['plan.patient', 'plan.location', 'service', 'preferredTherapist']);

        return view('rehab::plans.schedule', [
            'item' => $item,
            'progress' => $this->progress->forItem($item),
            'staff' => StaffMember::active()->whereIn('kind', ['terapeut', 'asistent', 'medic'])->pluck('display_name', 'id')->all(),
        ]);
    }

    /** Pasul 2: propunerea cu conflicte și alternative (SeriesScheduler::plan). */
    public function schedulePreview(Request $request, RehabPlanItem $item, SeriesScheduler $scheduler): View
    {
        abort_unless($request->user()->can('rehab.schedule'), 403);
        $data = $this->ruleData($request);
        $plan = $item->plan;
        $proposal = $scheduler->plan((int) $plan->patient_id, (int) $item->service_id, $data['staff_member_id'] ?? null, (int) $plan->location_id, $this->rule($data));

        return view('rehab::plans.schedule-preview', [
            'item' => $item->load(['plan.patient', 'service']),
            'data' => $data,
            'proposal' => $proposal,
            'staffMember' => isset($data['staff_member_id']) ? StaffMember::find($data['staff_member_id']) : null,
        ]);
    }

    /** Pasul 3: crearea seriei (parțial doar cu acord explicit). */
    public function scheduleStore(Request $request, RehabPlanItem $item): RedirectResponse
    {
        $data = $this->ruleData($request);
        $request->validate([
            'choice' => ['required', 'array'],
            'choice.*.start' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(\|\d*)?$/'],
            'allow_partial' => ['sometimes', 'boolean'],
        ]);
        $choices = [];
        foreach ($request->input('choice') as $index => $c) {
            [$start, $staff] = array_pad(explode('|', $c['start'], 2), 2, null);
            $choices[] = ['index' => (int) $index, 'start' => $start, 'skip' => empty($c['include']), 'staff_member_id' => $staff ?: null];
        }
        $result = $this->plans->scheduleItem($item, $request->user(), $this->rule($data), $choices, $data['staff_member_id'] ?? null, $request->boolean('allow_partial'));
        $msg = count($result['created']).' ședințe programate.';
        if ($result['failed']) {
            $msg .= ' '.count($result['failed']).' au rămas neprogramate și apar în evidența procedurii.';
        }

        return redirect()->route('panou.rehab.plans.show', $item->rehab_plan_id)->with('status', $msg);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'medical_record_id' => ['nullable', 'integer'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'payer_type' => ['required', Rule::in(array_keys(ServiceRecord::PAYER_TYPES))],
            'patient_package_id' => ['nullable', 'integer'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'planned_start' => ['nullable', 'date'],
        ]);
    }

    private function ruleData(Request $request): array
    {
        return $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'time' => ['required', 'date_format:H:i'],
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'staff_member_id' => ['nullable', 'integer', 'exists:staff_members,id'],
        ], ['weekdays.required' => 'Alegeți cel puțin o zi a săptămânii.']);
    }

    private function rule(array $data): array
    {
        return ['start_date' => $data['start_date'], 'weekdays' => array_map('intval', $data['weekdays']), 'time' => $data['time'], 'count' => (int) $data['count']];
    }

    private function formOptions(Patient $patient): array
    {
        return [
            'locations' => Location::active()->pluck('name', 'id')->all(),
            'records' => MedicalRecord::where('patient_id', $patient->id)->originals()->where('status', MedicalRecord::FINAL)
                ->orderByDesc('finalized_at')->get()->mapWithKeys(fn ($r) => [$r->id => $r->finalized_at->format('d.m.Y').' · '.$r->title])->all(),
            'packages' => PatientPackage::where('patient_id', $patient->id)->where('status', 'activ')->with('definition')->get()->mapWithKeys(fn ($p) => [$p->id => $p->label()])->all(),
            'payers' => ServiceRecord::PAYER_TYPES,
        ];
    }
}
