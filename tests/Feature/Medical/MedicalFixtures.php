<?php

namespace Tests\Feature\Medical;

use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Identity\Roles;
use App\Modules\Medical\Models\MedicalTemplate;
use App\Modules\Medical\Models\MedicalTemplateVersion;
use App\Modules\Medical\Support\TemplateSchema;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BookingRequest;
use Tests\Feature\Scheduling\SchedulingFixtures;

/** Date fictive pentru testele dosarului medical și ale recuperării. */
trait MedicalFixtures
{
    use SchedulingFixtures;

    protected User $medicUser;

    protected User $therapistUser;

    protected function setUpMedical(): void
    {
        $this->setUpClinic();
        $this->medicUser = User::factory()->withMfa()->create(['name' => 'Medic Test']);
        $this->medicUser->assignRole(Roles::MEDIC);
        $this->medic->update(['user_id' => $this->medicUser->id]);
        $this->therapistUser = User::factory()->withMfa()->create(['name' => 'Terapeut Test']);
        $this->therapistUser->assignRole(Roles::TERAPEUT);
        $this->therapist->update(['user_id' => $this->therapistUser->id]);
    }

    /** Acționează ca utilizatorul dat, în panou, cu MFA trecut. */
    protected function as(User $user, bool $reauth = false): static
    {
        $session = ['auth.started_at' => time(), 'mfa.passed' => true];
        if ($reauth) {
            $session['auth.reauth_at'] = time();
        }

        return $this->actingAs($user)->withSession($session);
    }

    /** Relație de îngrijire explicită (care_relations), valabilă azi. */
    protected function careVia(Patient $patient, StaffMember $staff): void
    {
        $patient->careRelations()->create(['staff_member_id' => $staff->id, 'source' => 'manual', 'valid_from' => now()->subDay()->toDateString()]);
    }

    /** Relație de îngrijire prin programare (dovada din modulul Scheduling). */
    protected function careViaAppointment(Patient $patient, StaffMember $staff, string $time = '09:00'): void
    {
        app(BookingService::class)->book(new BookingRequest($patient->id, $this->consult->id, $staff->id, $this->location->id, $this->at($time, 2)), null);
    }

    protected function template(array $sections = null): MedicalTemplate
    {
        $schema = TemplateSchema::normalize(['sections' => $sections ?? [
            ['title' => 'Secțiunea A', 'fields' => [
                ['label' => 'Motiv', 'type' => 'textarea', 'required' => true],
                ['label' => 'Valoare', 'type' => 'number', 'unit' => 'u'],
                ['label' => 'Opțiune', 'type' => 'select', 'options' => "unu\ndoi"],
            ]],
        ]]);
        $template = MedicalTemplate::create(['name' => 'Șablon test', 'draft_schema' => $schema, 'is_active' => true]);
        MedicalTemplateVersion::create(['medical_template_id' => $template->id, 'version' => 1, 'schema' => $schema, 'published_at' => now()]);

        return $template->fresh('latestVersion');
    }
}
