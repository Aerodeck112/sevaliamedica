<?php

namespace Tests\Feature\Rehab;

use App\Models\User;
use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Patients\Models\Patient;
use App\Modules\Rehab\Models\RehabPlan;
use App\Modules\Scheduling\Models\Appointment;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ecranele dosarului medical și ale recuperării se randează cu datele demonstrative, pe roluri. */
class ScreensTest extends TestCase
{
    private function login(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['auth.started_at' => time(), 'mfa.passed' => true]);

        return $user;
    }

    public function test_medic_screens(): void
    {
        $this->seed(DemoSeeder::class);
        $this->login('medic@demo.sevalia.invalid');
        $ana = Patient::where('first_name', 'Ana')->where('last_name', 'Exemplu')->firstOrFail();
        $original = MedicalRecord::where('patient_id', $ana->id)->whereNull('amends_id')->firstOrFail();
        $draft = MedicalRecord::where('status', MedicalRecord::DRAFT)->firstOrFail();
        $plan = RehabPlan::where('patient_id', $ana->id)->firstOrFail();

        foreach ([
            route('panou.dashboard') => 'Fișe de finalizat',
            route('panou.patients.show', [$ana, 'tab' => 'fise']) => 'Avertizare demonstrativă',
            route('panou.patients.show', [$ana, 'tab' => 'documente']) => 'Încarcă document',
            route('panou.patients.show', [$ana, 'tab' => 'consimtaminte']) => 'Consimțământ demonstrativ',
            route('panou.patients.show', [$ana, 'tab' => 'recuperare']) => 'Plan #'.$plan->id,
            route('panou.medical.records.show', $original) => 'Istoric',
            route('panou.medical.records.edit', $draft) => 'Finalizare',
            route('panou.medical.records.create', $ana) => 'Creează draftul',
            route('panou.medical.records.amend-form', $original) => 'Rectificare',
            route('panou.medical.drafts') => 'Fișe de finalizat',
            route('panou.medical.templates.index') => 'Fișă demonstrativă',
            route('panou.medical.templates.edit', MedicalTemplate::where('name', 'Fișă demonstrativă (fictivă)')->first()) => 'Previzualizare',
            route('panou.medical.templates.create') => 'Șablon nou',
            route('panou.rehab.plans.index') => 'Planuri de recuperare',
            route('panou.rehab.plans.show', $plan) => 'Evidența ședințelor',
            route('panou.rehab.plans.create', $ana) => 'Plan de recuperare nou',
            route('panou.rehab.plans.show', RehabPlan::where('status', 'draft')->first()) => 'Aprobă planul',
            route('panou.rehab.plans.items.schedule', $plan->items()->first()) => 'Regula seriei',
        ] as $url => $text) {
            $this->get($url)->assertOk()->assertSee($text, false);
        }
    }

    public function test_therapist_reception_and_manager_screens(): void
    {
        $this->seed(DemoSeeder::class);
        $elena = Patient::where('first_name', 'Elena')->firstOrFail();
        $plan = RehabPlan::where('patient_id', $elena->id)->firstOrFail();
        $seriesIds = DB::table('rehab_item_series')->pluck('appointment_series_id');
        $pending = Appointment::whereIn('series_id', $seriesIds)->where('patient_id', $elena->id)->where('status', 'confirmata')->where('starts_at', '<', now())->firstOrFail();

        $this->login('terapeut@demo.sevalia.invalid');
        $this->get(route('panou.dashboard'))->assertOk()->assertSee('Recuperare — ședințele mele');
        $this->get(route('panou.rehab.sessions.index'))->assertOk()->assertSee('De închis');
        $this->get(route('panou.rehab.sessions.create', $pending))->assertOk()->assertSee('De ce se marchează acum')->assertSee('Avertizare critică demonstrativă');
        $this->get(route('panou.rehab.plans.show', $plan))->assertOk()->assertSee('de clarificat');

        $this->login('receptie@demo.sevalia.invalid');
        $this->get(route('panou.rehab.plans.show', $plan))->assertOk()->assertSee('vizibile doar personalului medical')->assertDontSee('Obiectiv demonstrativ');
        $this->get(route('panou.patients.show', [$elena, 'tab' => 'recuperare']))->assertOk()->assertSee('Pachete de ședințe');

        $this->login('manager@demo.sevalia.invalid');
        $this->get(route('panou.medical.consent-templates.index'))->assertOk()->assertSee('de completat de clinică');
        $this->get(route('panou.medical.consent-templates.edit', ConsentTemplate::first()))->assertOk();
        $this->get(route('panou.rehab.packages.index'))->assertOk()->assertSee('Tip nou de pachet');
        $this->get(route('panou.medical.records.show', MedicalRecord::first()))->assertForbidden();
    }
}
