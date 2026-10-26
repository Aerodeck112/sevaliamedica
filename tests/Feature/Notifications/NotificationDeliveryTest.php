<?php

namespace Tests\Feature\Notifications;

use App\Core\Settings\Settings;
use App\Modules\Identity\Roles;
use App\Modules\Notifications\Models\FeedbackRequest;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Services\DeliveryDispatcher;
use App\Modules\Notifications\Services\NotificationPlanner;
use App\Modules\Notifications\Services\ReminderScanner;
use App\Modules\Notifications\Support\MessageType;
use App\Modules\Scheduling\Events\AppointmentStatusChanged;
use App\Modules\Scheduling\Models\PatientAccessToken;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Scenariul 6: notificări și reîncercări fără dubluri. */
class NotificationDeliveryTest extends TestCase
{
    use NotificationsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotifications();
        // Ora fixă în afara orelor de liniște.
        $this->travelTo(CarbonImmutable::today()->setTime(10, 0));
    }

    private function dispatch(): array
    {
        return app(DeliveryDispatcher::class)->run();
    }

    public function test_confirmation_is_planned_once_sent_once_and_contains_no_medical_data(): void
    {
        $this->production();
        $this->configureSms();
        Http::fake(['sms.exemplu.invalid/*' => Http::response(['data' => ['id' => 'm-1']], 200)]);
        $patient = $this->patient(['first_name' => 'Ana', 'allow_sms' => true]);

        $a = $this->book($patient->id, $this->at('11:00', 3));

        $this->assertSame(1, NotificationDelivery::where('appointment_id', $a->id)->where('type', MessageType::CONFIRMARE)->count());
        // Evenimente duplicate (ex. reemise) nu creează altă livrare.
        event(new AppointmentStatusChanged($a->fresh(), AppointmentStatus::SOLICITARE, AppointmentStatus::CONFIRMATA));
        event(new AppointmentStatusChanged($a->fresh(), AppointmentStatus::SOLICITARE, AppointmentStatus::CONFIRMATA));
        $this->assertSame(1, NotificationDelivery::where('appointment_id', $a->id)->where('type', MessageType::CONFIRMARE)->count());

        $this->dispatch();
        $this->dispatch(); // a doua rulare de cron nu retrimite

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($a) {
            $text = $request['text'];

            return $request['to'] === $a->patient->phone
                && str_contains($text, 'Ana') && str_contains($text, $a->starts_at->format('d.m.Y'))
                && str_contains($text, '/p/')
                && ! str_contains($text, 'Consultație test') // nu denumirea serviciului
                && $request->hasHeader('Authorization', 'Bearer test-secret');
        });
        $d = NotificationDelivery::where('appointment_id', $a->id)->first();
        $this->assertSame(NotificationDelivery::SENT, $d->status);
        $this->assertSame('m-1', $d->provider_message_id);
        $this->assertStringNotContainsString($patient->phone, (string) \DB::table('notification_deliveries')->where('id', $d->id)->value('recipient'), 'destinatarul e criptat');
        $this->assertSame(1, PatientAccessToken::where('appointment_id', $a->id)->count(), 'un singur link emis, chiar cu evenimente duplicate');
    }

    public function test_temporary_failure_is_retried_with_backoff_and_succeeds_without_duplicates(): void
    {
        $this->production();
        $this->configureSms();
        Http::fakeSequence('sms.exemplu.invalid/*')->push(['error' => 'busy'], 503)->push(['data' => ['id' => 'ok']], 200);
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));

        $this->dispatch();
        $d = NotificationDelivery::where('appointment_id', $a->id)->first();
        $this->assertSame(NotificationDelivery::FAILED, $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertStringContainsString('503', $d->last_error);

        $this->dispatch(); // încă în pauza de reîncercare
        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        $this->dispatch();
        $d->refresh();
        $this->assertSame(NotificationDelivery::SENT, $d->status);
        $this->assertSame(2, $d->attempts);
        Http::assertSentCount(2);
    }

    public function test_permanent_failure_is_not_retried_automatically_and_can_be_retried_manually(): void
    {
        $this->production();
        $this->configureSms();
        Http::fake(['sms.exemplu.invalid/*' => Http::response(['error' => 'invalid number 0712345678'], 400)]);
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));

        $this->dispatch();
        $d = NotificationDelivery::where('appointment_id', $a->id)->first();
        $this->assertSame(NotificationDelivery::FAILED_FINAL, $d->status);
        $this->assertStringNotContainsString('0712345678', (string) $d->last_error);

        $this->travel(1)->hours();
        $this->dispatch();
        Http::assertSentCount(1);

        $receptie = $this->actingAsRole(Roles::RECEPTIE);
        $this->post(route('panou.notifications.deliveries.retry', $d))->assertRedirect();
        $this->assertSame(NotificationDelivery::PENDING, $d->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notifications.retry', 'user_id' => $receptie->id]);
    }

    public function test_interrupted_send_becomes_unknown_and_is_not_resent_automatically(): void
    {
        $this->production();
        $this->configureSms();
        Http::fake();
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));
        $d = NotificationDelivery::where('appointment_id', $a->id)->first();
        // Simulăm un proces oprit după revendicare.
        $d->update(['status' => NotificationDelivery::SENDING, 'locked_until' => now()->subMinute(), 'attempts' => 1]);

        $this->dispatch();
        $this->assertSame(NotificationDelivery::UNKNOWN, $d->fresh()->status);
        $this->dispatch();
        Http::assertNothingSent();
    }

    public function test_a_delivery_cannot_be_claimed_twice(): void
    {
        $this->production();
        $this->configureSms();
        Http::fake();
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));
        $id = NotificationDelivery::where('appointment_id', $a->id)->value('id');

        $dispatcher = app(DeliveryDispatcher::class);
        $this->assertSame('sent', $dispatcher->deliver($id));
        $this->assertNull($dispatcher->deliver($id));
        Http::assertSentCount(1);
    }

    public function test_reminder_scan_is_idempotent_and_skips_recently_confirmed(): void
    {
        $this->configureSms();
        $patient = $this->patient(['allow_sms' => true]);
        // Programare făcută acum 5 zile, pentru mâine la 09:00: reminderul (24 h) intră în fereastră.
        $this->travelTo(CarbonImmutable::today()->subDays(5)->setTime(10, 0));
        $a = $this->book($patient->id, CarbonImmutable::tomorrow()->setTime(9, 0)->addDays(5));
        $this->travelTo(CarbonImmutable::today()->addDays(5)->setTime(10, 0));

        $scanner = app(ReminderScanner::class);
        $scanner->run();
        $scanner->run();
        $scanner->run();
        $this->assertSame(1, NotificationDelivery::where('appointment_id', $a->id)->where('type', MessageType::REMINDER)->count());

        // O programare confirmată chiar acum pentru mâine nu primește și reminder (are confirmarea).
        $b = $this->book($this->patient(['allow_sms' => true])->id, CarbonImmutable::tomorrow()->setTime(9, 0)->addDays(5));
        $scanner->run();
        $this->assertSame(0, NotificationDelivery::where('appointment_id', $b->id)->where('type', MessageType::REMINDER)->count());
    }

    public function test_obsolete_messages_are_cancelled_instead_of_sent(): void
    {
        $this->production();
        $this->configureSms();
        Http::fake();
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));

        app(BookingService::class)->changeStatus($a, AppointmentStatus::ANULATA, null, 'Indisponibil (test)');
        $this->dispatch();

        $confirmation = NotificationDelivery::where('appointment_id', $a->id)->where('type', MessageType::CONFIRMARE)->first();
        $this->assertSame(NotificationDelivery::CANCELLED, $confirmation->status);
        $this->assertSame(NotificationDelivery::SENT, NotificationDelivery::where('appointment_id', $a->id)->where('type', MessageType::ANULARE)->value('status'));
        Http::assertSentCount(1);
    }

    public function test_move_sends_only_the_latest_reschedule_message(): void
    {
        $this->production();
        $this->configureSms();
        Http::fake();
        $user = $this->actingAsRole(Roles::RECEPTIE);
        $booking = app(BookingService::class);
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));
        $this->dispatch(); // confirmarea

        $a = $booking->move($a->fresh(), $this->at('13:00', 3), null, null, null, $user, 'Cerere pacient (test)');
        $a = $booking->move($a->fresh(), $this->at('14:00', 3), null, null, null, $user, 'Cerere pacient (test)');
        $this->dispatch();

        $moves = NotificationDelivery::where('appointment_id', $a->id)->where('type', MessageType::REPROGRAMARE)->get();
        $this->assertCount(2, $moves);
        $this->assertSame([NotificationDelivery::CANCELLED, NotificationDelivery::SENT], $moves->sortBy('id')->pluck('status')->values()->all());
        Http::assertSent(fn ($r) => str_contains($r['text'], '14:00'));
        Http::assertNotSent(fn ($r) => str_contains($r['text'], '13:00'));
    }

    public function test_patient_preferences_are_respected(): void
    {
        $this->configureSms();
        $this->configureEmailLog();
        // Pacientul nu permite SMS, permite e-mail.
        $p = $this->patient(['allow_sms' => false, 'allow_email' => true, 'email' => 'ana@demo.sevalia.invalid']);
        $a = $this->book($p->id, $this->at('11:00', 3));
        $this->assertSame(['email'], NotificationDelivery::where('appointment_id', $a->id)->pluck('channel')->all());

        // Pacient fără niciun canal permis: nu se trimite nimic, dar motivul rămâne vizibil.
        $none = $this->book($this->patient(['allow_sms' => false, 'allow_email' => false])->id, $this->at('13:00', 3));
        $d = NotificationDelivery::where('appointment_id', $none->id)->sole();
        $this->assertSame(NotificationDelivery::CANCELLED, $d->status);
        $this->assertSame('niciunul', $d->channel);
    }

    public function test_unconfigured_channel_sends_nothing(): void
    {
        $this->production();
        Http::fake();
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));
        $this->dispatch();

        Http::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::whereIn('status', [NotificationDelivery::SENT, NotificationDelivery::PENDING])->count());
        $this->assertSame('Niciun canal configurat și permis de pacient.', NotificationDelivery::where('appointment_id', $a->id)->value('skip_reason'));
    }

    public function test_staging_blocks_real_sending(): void
    {
        config(['sevalia.stage' => 'staging']);
        $this->configureSms();
        Http::fake();
        $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));
        $this->dispatch();

        Http::assertNothingSent();
        $this->assertSame(NotificationDelivery::SIMULATED, NotificationDelivery::value('status'));
    }

    public function test_quiet_hours_postpone_sending(): void
    {
        $this->configureSms();
        $this->travelTo(CarbonImmutable::today()->setTime(22, 30));
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('11:00', 3));

        $d = NotificationDelivery::where('appointment_id', $a->id)->first();
        $this->assertEquals(CarbonImmutable::tomorrow()->setTime(8, 0), CarbonImmutable::parse($d->scheduled_for));
        $this->dispatch();
        $this->assertSame(NotificationDelivery::PENDING, $d->fresh()->status);
    }

    public function test_series_sessions_do_not_each_get_a_confirmation(): void
    {
        $this->configureSms();
        $series = \App\Modules\Scheduling\Models\AppointmentSeries::create([
            'patient_id' => $this->patient(['allow_sms' => true])->id, 'service_id' => $this->consult->id, 'location_id' => $this->location->id,
            'rule' => [], 'planned_count' => 2,
        ]);
        $a = $this->book($series->patient_id, $this->at('11:00', 3), $series->id);
        $this->assertSame(0, NotificationDelivery::where('appointment_id', $a->id)->count());
    }

    public function test_templates_reject_variables_outside_the_whitelist(): void
    {
        $this->actingAsRole(Roles::MANAGER);
        $this->put(route('panou.notifications.templates.update', [MessageType::CONFIRMARE, 'sms']), [
            'body' => 'Diagnostic: {diagnostic} pentru {prenume}', 'action' => 'save',
        ])->assertSessionHasErrors('body');
        $this->assertSame(1, NotificationTemplate::where('type', MessageType::CONFIRMARE)->where('channel', 'sms')->count());

        $this->put(route('panou.notifications.templates.update', [MessageType::CONFIRMARE, 'sms']), [
            'body' => 'SEVALIA: {prenume}, vă așteptăm pe {data} la {ora}.', 'action' => 'save',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, NotificationTemplate::active(MessageType::CONFIRMARE, 'sms')->version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notifications.template_saved']);
    }

    public function test_feedback_request_flow(): void
    {
        app(Settings::class)->set('notifications.type.feedback', true);
        $this->configureSms();
        $user = $this->actingAsRole(Roles::RECEPTIE);
        $a = $this->book($this->patient(['allow_sms' => true])->id, $this->at('10:30', 0));
        $booking = app(BookingService::class);
        $booking->changeStatus($a, AppointmentStatus::SOSIT, $user);
        $booking->changeStatus($a->fresh(), AppointmentStatus::IN_DESFASURARE, $user);
        $booking->changeStatus($a->fresh(), AppointmentStatus::FINALIZATA, $user);

        $fb = NotificationDelivery::where('appointment_id', $a->id)->where('type', MessageType::FEEDBACK)->sole();
        $this->assertTrue(CarbonImmutable::parse($fb->scheduled_for)->greaterThan(now()), 'pleacă după întârzierea configurată');
        preg_match('~/feedback/([A-Za-z0-9_-]+)~', $fb->payload['body'], $m);
        $token = $m[1];

        $this->get('/feedback/'.$token)->assertOk()->assertSee('Cum a fost vizita?')->assertSee('nu includeți informații medicale', false);
        $this->post('/feedback/'.$token, ['rating' => 5, 'comment' => 'Personal amabil'])->assertOk()->assertSee('Vă mulțumim');
        $this->post('/feedback/'.$token, ['rating' => 1])->assertSessionHasErrors('rating');
        $this->assertSame(5, FeedbackRequest::sole()->rating);
        $this->get('/feedback/'.str_repeat('x', 43))->assertNotFound();
    }

    public function test_access_between_roles(): void
    {
        $this->actingAsRole(Roles::TERAPEUT);
        $this->get(route('panou.notifications.deliveries'))->assertForbidden();

        $this->actingAsRole(Roles::RECEPTIE);
        $this->get(route('panou.notifications.deliveries'))->assertOk();
        $this->get(route('panou.notifications.channels'))->assertForbidden();
        $this->get(route('panou.notifications.templates'))->assertForbidden();

        $this->actingAsRole(Roles::ADMIN_TEHNIC);
        $this->get(route('panou.notifications.channels'))->assertOk()->assertSee('trimiterea reală este oprită');
        // Credențialele se schimbă numai după reconfirmare.
        $this->put(route('panou.notifications.channels.update', 'sms'), ['driver' => 'log', 'enabled' => 1])->assertRedirect(route('panou.reauth'));
        $this->withRecentReauth()->put(route('panou.notifications.channels.update', 'sms'), [
            'driver' => 'http_generic', 'enabled' => 1,
            'config' => ['url' => 'https://sms.exemplu.invalid/send', 'field_to' => 'to', 'field_message' => 'text', 'auth_value' => 'secret-nou-123'],
        ])->assertRedirect();
        $log = \App\Core\Audit\AuditLog::where('action', 'notifications.channel_updated')->latest('id')->first();
        $this->assertStringNotContainsString('secret-nou-123', json_encode($log->context));
        $this->get(route('panou.notifications.channels'))->assertOk()->assertDontSee('secret-nou-123');
    }

    public function test_site_request_gets_registration_message_without_promising_a_slot(): void
    {
        $this->configureEmailLog();
        $result = app(\App\Core\Contracts\AppointmentIntake::class)->submit(new \App\Core\Contracts\PublicAppointmentRequest(
            'Fictiv Solicitant', null, 'solicitant@demo.sevalia.invalid', 'email', null, null, null, $this->location->id, null, null, null,
            CarbonImmutable::now(), 'https://exemplu.test/contactus', hash('sha256', '127.0.0.1'),
        ));
        $d = NotificationDelivery::where('type', MessageType::SOLICITARE)->sole();
        $this->assertSame('email', $d->channel);
        $this->assertStringContainsString($result->reference, $d->payload['body']);
        $this->assertStringContainsString('nu este încă rezervat', $d->payload['body']);
    }

    public function test_planner_masks_addresses(): void
    {
        $this->assertSame('07•••••101', NotificationPlanner::mask('0700 000 101'));
        $this->assertSame('a•••@demo.sevalia.invalid', NotificationPlanner::mask('ana@demo.sevalia.invalid'));
    }
}
