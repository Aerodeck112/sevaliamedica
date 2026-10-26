<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Medical\Models\ClinicalAlert;
use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Medical\Models\MedicalTemplateVersion;
use App\Modules\Medical\Models\PatientConsent;
use App\Modules\Medical\Services\RecordService;
use App\Modules\Medical\Support\TemplateSchema;
use App\Modules\Patients\Models\Patient;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Rehab\Models\RehabSessionRecord;
use App\Modules\Rehab\Services\PlanService;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentSeries;
use App\Modules\Scheduling\Services\ServiceRecordService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Date DEMONSTRATIVE, fictive, pentru dosarul medical și recuperare. Textele clinice sunt evident fictive
 * („text demonstrativ”) și nu reprezintă recomandări medicale.
 */
class MedicalRehabDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() || config('sevalia.stage') === 'production') {
            throw new RuntimeException('Datele demonstrative nu pot fi încărcate în producție.');
        }
        if (MedicalTemplate::where('name', 'Fișă demonstrativă (fictivă)')->exists()) {
            return;
        }

        $medicUser = User::where('email', 'medic@demo.sevalia.invalid')->firstOrFail();
        $receptie = User::where('email', 'receptie@demo.sevalia.invalid')->firstOrFail();
        $terapeutUser = User::where('email', 'terapeut@demo.sevalia.invalid')->firstOrFail();
        $medic = StaffMember::where('user_id', $medicUser->id)->firstOrFail();
        $therapist = StaffMember::where('user_id', $terapeutUser->id)->firstOrFail();
        $therapist2 = StaffMember::where('display_name', 'Ioana Exemplu (terapeut fictiv)')->firstOrFail();
        $unirii = Location::where('slug', 'cartier-unirii')->firstOrFail();
        $patients = Patient::where('admin_notes', 'like', 'Pacient fictiv%')->orderBy('id')->get();
        $ana = $patients[0];
        $mihai = $patients[1];
        $elena = $patients[2];

        // Relații explicite de îngrijire pentru demonstrație (medicul și terapeutul cu pacienții din plan).
        foreach ([[$ana, $medic], [$elena, $medic], [$elena, $therapist], [$ana, $therapist]] as [$p, $s]) {
            $p->careRelations()->firstOrCreate(['staff_member_id' => $s->id], ['source' => 'manual', 'reason' => 'Demonstrație', 'valid_from' => now()->subMonth()->toDateString(), 'created_by' => $receptie->id]);
        }

        Auth::login($medicUser);
        $reab = Specialty::where('slug', 'reabilitare-medicala')->first();
        $template = MedicalTemplate::create([
            'name' => 'Fișă demonstrativă (fictivă)',
            'specialty_id' => $reab?->id,
            'description' => 'Șablon fictiv pentru demonstrarea tipurilor de câmpuri. Nu este un protocol medical.',
            'draft_schema' => TemplateSchema::normalize(['sections' => [
                ['title' => 'Prezentare', 'fields' => [
                    ['label' => 'Motivul prezentării', 'type' => 'textarea', 'required' => true],
                    ['label' => 'Data debutului (exemplu)', 'type' => 'date'],
                ]],
                ['title' => 'Examinare (exemplu)', 'fields' => [
                    ['label' => 'Greutate', 'type' => 'number', 'unit' => 'kg'],
                    ['label' => 'Partea (exemplu)', 'type' => 'select', 'options' => "stânga\ndreapta\nbilateral"],
                    ['label' => 'Scală (exemplu)', 'type' => 'scale', 'min' => 0, 'max' => 10],
                    ['label' => 'Măsurători (exemplu)', 'type' => 'table', 'columns' => "Segment\nValoare", 'rows' => 3],
                    ['label' => 'Examen (text liber)', 'type' => 'textarea'],
                ]],
                ['title' => 'Concluzii', 'fields' => [
                    ['label' => 'Concluzii', 'type' => 'textarea', 'required' => true],
                    ['label' => 'Control recomandat', 'type' => 'checkbox'],
                ]],
            ]]),
            'created_by' => $medicUser->id,
        ]);
        MedicalTemplateVersion::create(['medical_template_id' => $template->id, 'version' => 1, 'schema' => $template->draft_schema, 'published_by' => $medicUser->id, 'published_at' => now()->subWeeks(3)]);
        $template->load('latestVersion');

        $records = app(RecordService::class);
        $values = fn (string $who) => [
            'motivul_prezentarii' => "Text demonstrativ pentru {$who}. Nu reprezintă date medicale reale.",
            'data_debutului_exemplu' => now()->subWeeks(5)->toDateString(),
            'greutate' => '72,5',
            'partea_exemplu' => 'dreapta',
            'scala_exemplu' => '5',
            'masuratori_exemplu' => [['Segment A', '10'], ['Segment B', '12']],
            'examen_text_liber' => 'Text demonstrativ.',
            'concluzii' => 'Concluzie demonstrativă (fictivă).',
            'control_recomandat' => '1',
        ];

        // Ana: consultație finalizată (baza planului), apoi o rectificare finalizată.
        $consultAna = $records->createDraft($ana, $template, $medicUser, ['title' => 'Consultație inițială (demo)']);
        $records->saveDraft($consultAna, $values('Ana'), 1, $medicUser);
        $consultAna = $records->finalize($consultAna->fresh(), $medicUser);
        $rect = $records->amend($consultAna, 'rectificare', 'Corectarea valorii introduse greșit la greutate (demo).', $medicUser);
        $records->saveDraft($rect, ['greutate' => '75'] + $values('Ana'), 1, $medicUser);
        $records->finalize($rect->fresh(), $medicUser);
        $completare = $records->amend($consultAna, 'completare', 'Adăugare informații primite ulterior (demo).', $medicUser);
        $records->saveDraft($completare, ['text' => 'Completare demonstrativă: pacientul a adus rezultatele unei investigații (fictiv).'], 1, $medicUser);
        $records->finalize($completare->fresh(), $medicUser);

        // Mihai: o fișă rămasă în draft (apare la „Fișe de finalizat”).
        $draft = $records->createDraft($mihai, $template, $medicUser, ['title' => 'Consultație (draft demo)']);
        $records->saveDraft($draft, ['motivul_prezentarii' => 'Text demonstrativ, încă necompletat.'], 1, $medicUser);

        // Elena: consultație finalizată.
        $consultElena = $records->createDraft($elena, $template, $medicUser, ['title' => 'Consultație (demo)']);
        $records->saveDraft($consultElena, $values('Elena'), 1, $medicUser);
        $consultElena = $records->finalize($consultElena->fresh(), $medicUser);

        ClinicalAlert::create(['patient_id' => $ana->id, 'severity' => 'atentie', 'text' => 'Avertizare demonstrativă (fictivă): verificați înainte de procedură.', 'staff_member_id' => $medic->id, 'created_by' => $medicUser->id]);
        ClinicalAlert::create(['patient_id' => $elena->id, 'severity' => 'critic', 'text' => 'Avertizare critică demonstrativă (fictivă).', 'staff_member_id' => $medic->id, 'created_by' => $medicUser->id]);

        $consentTemplate = ConsentTemplate::create(['name' => 'Consimțământ demonstrativ (fictiv)', 'scope' => 'Demonstrație', 'created_by' => $medicUser->id]);
        $consentVersion = ConsentTemplateVersion::create(['consent_template_id' => $consentTemplate->id, 'version' => 1,
            'body' => "TEXT DEMONSTRATIV — nu este un text juridic sau medical.\n\nClinica va introduce aici textul consimțământului validat de responsabilii săi.",
            'published_by' => $medicUser->id, 'published_at' => now()->subWeeks(3)]);
        PatientConsent::create(['patient_id' => $ana->id, 'consent_template_version_id' => $consentVersion->id, 'method' => 'verbal_consemnat',
            'given_by' => 'pacient', 'given_at' => now()->subWeeks(2), 'collected_by' => $medicUser->id, 'notes' => 'Consemnare demonstrativă.']);

        // Plan pentru Ana: kinetoterapia din seria demo existentă + AlterG programat de recepție.
        $plans = app(PlanService::class);
        $kineto = Service::where('code', 'DEMO-KINETO')->firstOrFail();
        $alterg = Service::where('code', 'DEMO-ALTERG')->firstOrFail();
        $laser = Service::where('code', 'DEMO-LASER')->firstOrFail();

        $planAna = $plans->create($ana, $medicUser, ['medical_record_id' => $consultAna->id, 'location_id' => $unirii->id, 'payer_type' => 'privat',
            'objectives' => 'Obiectiv demonstrativ (fictiv).', 'notes' => 'Observație demonstrativă pentru echipă.', 'planned_start' => now()->toDateString()]);
        $itemKineto = $plans->addItem($planAna, $medicUser, ['service_id' => $kineto->id, 'sessions_count' => 10, 'sessions_per_week' => 3, 'preferred_therapist_id' => $therapist->id]);
        $itemAlterg = $plans->addItem($planAna, $medicUser, ['service_id' => $alterg->id, 'sessions_count' => 6, 'sessions_per_week' => 2, 'preferred_therapist_id' => $therapist2->id]);
        $plans->approve($planAna, $medicUser);
        $existing = AppointmentSeries::where('patient_id', $ana->id)->where('service_id', $kineto->id)->first();
        if ($existing) {
            DB::table('rehab_item_series')->insert(['rehab_plan_item_id' => $itemKineto->id, 'appointment_series_id' => $existing->id, 'created_at' => now()]);
            $planAna->update(['status' => RehabPlan::ACTIVE]);
        }
        $rule = ['start_date' => CarbonImmutable::today()->next('Tuesday')->toDateString(), 'weekdays' => [2, 4], 'time' => '12:30', 'count' => 6];
        $proposal = app(\App\Modules\Scheduling\Services\SeriesScheduler::class)->plan($ana->id, $alterg->id, $therapist2->id, $unirii->id, $rule);
        $plans->scheduleItem($itemAlterg->fresh(), $receptie, $rule,
            array_map(fn ($i) => ['index' => $i['index'], 'start' => $i['start']->format('Y-m-d H:i'), 'skip' => (bool) $i['conflicts']], $proposal),
            $therapist2->id, true);

        // Plan pentru Elena cu istoric: ședințe trecute efectuate, o neprezentare, una de clarificat, restul programate.
        $planElena = $plans->create($elena, $medicUser, ['medical_record_id' => $consultElena->id, 'location_id' => $unirii->id, 'payer_type' => 'privat',
            'objectives' => 'Obiectiv demonstrativ (fictiv).', 'planned_start' => now()->subWeeks(2)->toDateString()]);
        $itemLaser = $plans->addItem($planElena, $medicUser, ['service_id' => $laser->id, 'sessions_count' => 8, 'sessions_per_week' => 3, 'preferred_therapist_id' => $therapist->id]);
        $plans->approve($planElena, $medicUser);

        $series = AppointmentSeries::create(['patient_id' => $elena->id, 'service_id' => $laser->id, 'staff_member_id' => $therapist->id, 'location_id' => $unirii->id,
            'rule' => ['start_date' => now()->subWeeks(2)->toDateString(), 'weekdays' => [1, 3, 5], 'time' => '08:00', 'count' => 5], 'planned_count' => 5,
            'notes' => 'Serie demonstrativă cu istoric', 'created_by' => $receptie->id]);
        DB::table('rehab_item_series')->insert(['rehab_plan_item_id' => $itemLaser->id, 'appointment_series_id' => $series->id, 'created_at' => now()]);
        $planElena->update(['status' => RehabPlan::ACTIVE]);

        $serviceRecords = app(ServiceRecordService::class);
        $days = [-12, -10, -8, -5, -3];
        $states = [AppointmentStatus::FINALIZATA, AppointmentStatus::FINALIZATA, AppointmentStatus::FINALIZATA, AppointmentStatus::NEPREZENTAT, AppointmentStatus::CONFIRMATA];
        foreach ($days as $i => $offset) {
            // Programări trecute create direct (doar pentru demonstrație); cele viitoare trec prin BookingService.
            $start = CarbonImmutable::today()->addDays($offset)->setTime(8, 0);
            $a = Appointment::create(['patient_id' => $elena->id, 'service_id' => $laser->id, 'staff_member_id' => $therapist->id, 'location_id' => $unirii->id,
                'series_id' => $series->id, 'series_index' => $i + 1, 'starts_at' => $start, 'ends_at' => $start->addMinutes($laser->duration_minutes),
                'blocked_until' => $start->addMinutes($laser->duration_minutes + $laser->buffer_minutes), 'status' => $states[$i], 'source' => 'serie', 'payer_type' => 'privat',
                'cancel_reason' => $states[$i] === AppointmentStatus::NEPREZENTAT ? 'Nu s-a prezentat (demo)' : null, 'created_by' => $receptie->id]);
            if ($states[$i] === AppointmentStatus::FINALIZATA) {
                $record = $serviceRecords->fromAppointment($a, ['payer_type' => 'privat'], $terapeutUser);
                RehabSessionRecord::create(['rehab_plan_item_id' => $itemLaser->id, 'appointment_id' => $a->id, 'service_record_id' => $record->id,
                    'therapist_staff_id' => $therapist->id, 'performed_at' => $start, 'observations' => 'Observație demonstrativă (fictivă).',
                    'evolution' => 'Evoluție demonstrativă (fictivă).', 'recorded_by' => $terapeutUser->id]);
            }
        }
        $ruleFuture = ['start_date' => CarbonImmutable::today()->addDay()->toDateString(), 'weekdays' => [1, 3, 5], 'time' => '08:00', 'count' => 2];
        $proposalFuture = app(\App\Modules\Scheduling\Services\SeriesScheduler::class)->plan($elena->id, $laser->id, $therapist->id, $unirii->id, $ruleFuture);
        $plans->scheduleItem($itemLaser->fresh(), $receptie, $ruleFuture,
            array_map(fn ($i) => ['index' => $i['index'], 'start' => $i['start']->format('Y-m-d H:i'), 'skip' => (bool) $i['conflicts']], $proposalFuture),
            $therapist->id, true);

        // Un plan în draft (fără aprobare) pentru Mihai.
        $planMihai = $plans->create($mihai, $medicUser, ['location_id' => $unirii->id, 'payer_type' => 'cas', 'objectives' => 'Obiectiv demonstrativ (fictiv).']);
        $plans->addItem($planMihai, $medicUser, ['service_id' => $kineto->id, 'sessions_count' => 10, 'sessions_per_week' => 2]);

        Auth::logout();
    }
}
