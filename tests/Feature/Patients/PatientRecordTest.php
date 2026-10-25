<?php

namespace Tests\Feature\Patients;

use App\Core\Audit\AuditLog;
use App\Modules\Identity\Roles;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientMerge;
use App\Modules\Scheduling\Models\Appointment;
use Database\Factories\PatientFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientRecordTest extends TestCase
{
    public function test_cnp_is_encrypted_indexed_and_masked(): void
    {
        $cnp = PatientFactory::fakeCnp();
        $p = Patient::factory()->withCnp($cnp)->create();
        $raw = DB::table('patients')->where('id', $p->id)->first();
        $this->assertNotSame($cnp, $raw->cnp);
        $this->assertStringNotContainsString($cnp, $raw->cnp);
        $this->assertSame(64, strlen($raw->cnp_index));
        $this->assertSame($cnp, $p->fresh()->cnp);
        $this->assertStringContainsString('•', $p->maskedCnp());
        $this->assertTrue(Patient::search($cnp)->whereKey($p->id)->exists(), 'căutare exactă după CNP');
    }

    public function test_identical_cnp_blocks_creation_with_link_to_existing(): void
    {
        $cnp = PatientFactory::fakeCnp();
        $existing = Patient::factory()->withCnp($cnp)->create();
        $this->actingAsRole(Roles::RECEPTIE);

        $this->post(route('panou.patients.store'), ['last_name' => 'Altul', 'first_name' => 'Nume', 'cnp' => $cnp])
            ->assertSessionHasErrors('cnp')
            ->assertSessionHas('duplicates', fn ($d) => $d['blocking'][0]['id'] === $existing->id);
        $this->assertSame(1, Patient::count());

        $this->postJson(route('panou.patients.store'), ['last_name' => 'Altul', 'first_name' => 'Nume', 'cnp' => $cnp])
            ->assertStatus(422)->assertJsonPath('blocking.0.id', $existing->id);
    }

    public function test_similar_patient_warns_and_requires_explicit_confirmation(): void
    {
        Patient::factory()->create(['last_name' => 'Exemplu', 'first_name' => 'Ana', 'birth_date' => '1980-05-05', 'phone' => '0700 555 111']);
        $this->actingAsRole(Roles::RECEPTIE);
        $data = ['last_name' => 'Exemplu', 'first_name' => 'Ana', 'birth_date' => '1980-05-05'];

        $this->post(route('panou.patients.store'), $data)->assertSessionHas('warning');
        $this->assertSame(1, Patient::count(), 'fără fuziune și fără creare automată');
        $this->postJson(route('panou.patients.store'), ['last_name' => 'Diferit', 'first_name' => 'X', 'phone' => '+40 700 555 111'])->assertStatus(409);

        $this->post(route('panou.patients.store'), $data + ['confirm_not_duplicate' => 1])->assertRedirect();
        $this->assertSame(2, Patient::count());
    }

    public function test_invalid_cnp_is_rejected_and_birth_date_derived(): void
    {
        $this->actingAsRole(Roles::RECEPTIE);
        $this->post(route('panou.patients.store'), ['last_name' => 'A', 'first_name' => 'B', 'cnp' => '1234567890123'])->assertSessionHasErrors('cnp');

        $cnp = PatientFactory::fakeCnp();
        $this->post(route('panou.patients.store'), ['last_name' => 'A', 'first_name' => 'B', 'cnp' => $cnp])->assertRedirect();
        $p = Patient::firstOrFail();
        $this->assertNotNull($p->birth_date);
        $this->assertContains($p->sex, ['F', 'M']);
    }

    public function test_viewing_and_revealing_identifiers_is_audited(): void
    {
        $p = Patient::factory()->withCnp()->create();
        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.patients.show', $p))->assertOk()->assertSee($p->fullName())->assertDontSee($p->cnp);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patients.viewed', 'patient_id' => $p->id]);

        $this->postJson(route('panou.patients.reveal-cnp', $p))->assertOk()->assertJsonPath('cnp', $p->cnp);
        $log = AuditLog::where('action', 'patients.cnp_revealed')->firstOrFail();
        $this->assertStringNotContainsString($p->cnp, json_encode($log->context));
    }

    public function test_merge_requires_permission_reauth_reason_and_moves_records(): void
    {
        $source = Patient::factory()->create(['last_name' => 'Dublură', 'first_name' => 'Ion']);
        $target = Patient::factory()->withCnp()->create(['last_name' => 'Dublura', 'first_name' => 'Ion']);
        $appt = Appointment::create(['patient_id' => $source->id, 'status' => 'solicitare', 'source' => 'receptie']);
        $source->representatives()->create(['name' => 'Rep Test', 'relationship' => 'parinte']);

        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.patients.merge.create', $source))->assertForbidden();

        $this->actingAsRole(Roles::MANAGER);
        $this->get(route('panou.patients.merge.create', $source))->assertRedirect(route('panou.reauth'));
        $this->withRecentReauth()->post(route('panou.patients.merge.store', $source), ['target_id' => $target->id, 'reason' => 'scurt', 'confirm' => 1])
            ->assertSessionHasErrors('reason');
        $this->withRecentReauth()->post(route('panou.patients.merge.store', $source), ['target_id' => $target->id, 'reason' => 'Aceeași persoană, verificat act de identitate', 'confirm' => 1])
            ->assertRedirect(route('panou.patients.show', $target));

        $this->assertSame($target->id, $source->fresh()->merged_into_id);
        $this->assertSame($target->id, $appt->fresh()->patient_id);
        $this->assertSame(1, $target->representatives()->count());
        $merge = PatientMerge::firstOrFail();
        $this->assertSame(1, $merge->summary['Programări și solicitări']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patients.merged', 'patient_id' => $target->id]);
        $this->assertFalse(Patient::active()->whereKey($source->id)->exists(), 'fișa sursă nu mai apare în liste');
        $this->get(route('panou.patients.show', $source))->assertRedirect(route('panou.patients.show', $target));
    }

    public function test_patients_with_different_cnp_cannot_be_merged(): void
    {
        $a = Patient::factory()->withCnp()->create();
        $b = Patient::factory()->withCnp()->create();
        $this->actingAsRole(Roles::MANAGER);
        $this->withRecentReauth()->post(route('panou.patients.merge.store', $a), ['target_id' => $b->id, 'reason' => 'Test de unire nepermisă', 'confirm' => 1])
            ->assertSessionHasErrors('target_id');
        $this->assertNull($a->fresh()->merged_into_id);
    }

    public function test_marketing_consent_is_separate_and_timestamped(): void
    {
        $p = Patient::factory()->create();
        $this->actingAsRole(Roles::RECEPTIE);
        $this->put(route('panou.patients.update', $p), ['last_name' => $p->last_name, 'first_name' => $p->first_name, 'allow_sms' => 1, 'marketing_consent' => 0])->assertRedirect();
        $this->assertTrue($p->fresh()->allow_sms);
        $this->assertNull($p->fresh()->marketing_consent_at);
        $this->put(route('panou.patients.update', $p), ['last_name' => $p->last_name, 'first_name' => $p->first_name, 'marketing_consent' => 1])->assertRedirect();
        $this->assertNotNull($p->fresh()->marketing_consent_at);
        $this->assertStringContainsString('panou', $p->fresh()->marketing_consent_source);
    }
}
