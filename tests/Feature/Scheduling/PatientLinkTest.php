<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Models\PatientAccessToken;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Services\PatientLinkService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingRequest;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PatientLinkTest extends TestCase
{
    use SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinic();
    }

    private function appointment(int $daysAhead = 2)
    {
        return app(BookingService::class)->book(new BookingRequest($this->patient()->id, $this->consult->id, $this->medic->id, $this->location->id, $this->at('10:00', $daysAhead)), null);
    }

    public function test_token_is_stored_hashed_and_page_shows_no_medical_data(): void
    {
        $a = $this->appointment();
        $link = app(PatientLinkService::class)->issue($a);
        $this->assertGreaterThanOrEqual(43, strlen($link['token']));
        $this->assertDatabaseMissing('patient_access_tokens', ['token_hash' => $link['token']]);
        $this->assertDatabaseHas('patient_access_tokens', ['token_hash' => hash('sha256', $link['token'])]);

        $res = $this->get($link['url'])->assertOk()->assertSee('Programarea dumneavoastră')->assertSee($this->location->name)
            ->assertDontSee($this->consult->name)->assertDontSee($a->patient->fullName());
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $res->headers->get('X-Robots-Tag'));
    }

    public function test_cancel_is_single_use_and_frees_the_slot(): void
    {
        Event::fake([AppointmentCancelled::class]);
        $a = $this->appointment();
        $link = app(PatientLinkService::class)->issue($a);

        $this->post(route('patient-link.cancel', $link['token']))->assertRedirect(route('patient-link.show', $link['token']));
        $this->assertSame(AppointmentStatus::ANULATA, $a->fresh()->status);
        $this->assertSame(0, $a->activeResources()->count());
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $a->id, 'type' => 'anulata_pacient']);
        Event::assertDispatched(AppointmentCancelled::class, fn ($e) => $e->byPatient);

        $this->post(route('patient-link.cancel', $link['token']))->assertSessionHasErrors('token');
    }

    public function test_expired_or_unknown_tokens_are_refused(): void
    {
        $a = $this->appointment();
        $link = app(PatientLinkService::class)->issue($a);
        PatientAccessToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->get($link['url'])->assertOk()->assertSee('Linkul a expirat');
        $this->post(route('patient-link.cancel', $link['token']))->assertSessionHasErrors('token');
        $this->assertSame(AppointmentStatus::CONFIRMATA, $a->fresh()->status);

        $this->get('/p/'.str_repeat('a', 43))->assertNotFound();
    }

    public function test_cancel_too_close_to_appointment_is_refused_but_reschedule_request_is_recorded(): void
    {
        $a = $this->appointment();
        $link = app(PatientLinkService::class)->issue($a);
        $this->travelTo($a->starts_at->subHour()); // cu o oră înainte (sub pragul implicit de 2 ore)

        $this->get($link['url'])->assertSee('Anularea online nu mai este posibilă');
        $this->post(route('patient-link.cancel', $link['token']))->assertSessionHasErrors('token');

        $this->post(route('patient-link.reschedule', $link['token']), ['message' => 'Joi după ora 16'])->assertRedirect();
        $this->assertNotNull($a->fresh()->reschedule_requested_at);
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $a->id, 'type' => 'reprogramare_ceruta', 'reason' => 'Joi după ora 16']);
    }
}
