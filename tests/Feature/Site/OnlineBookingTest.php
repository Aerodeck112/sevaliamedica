<?php

namespace Tests\Feature\Site;

use App\Core\Contracts\PublicBooking;
use App\Core\Settings\Settings;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingRequest;
use App\Modules\Site\Services\FormToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Scheduling\SchedulingFixtures;
use Tests\TestCase;

/** Programarea online de pe site: intervale libere reale, reverificare la trimitere, reguli de confirmare. */
class OnlineBookingTest extends TestCase
{
    use SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinic();
        $this->medic->update(['show_on_site' => true]);
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays(3);
    }

    private function payload(CarbonImmutable $start, array $overrides = []): array
    {
        return $overrides + [
            FormToken::FIELD => FormToken::issue('programare', time() - 30),
            'service' => $this->consult->id, 'location' => $this->location->id, 'staff' => '',
            'start' => $start->toIso8601String(),
            'name' => 'Pacient Demo Fictiv', 'phone' => '0700 000 000', 'email' => 'pacient@demo.sevalia.invalid',
            'contact' => 'telefon', 'consent' => '1',
        ];
    }

    public function test_steps_show_services_days_and_real_free_slots(): void
    {
        $this->get('/programare-online')->assertOk()->assertSee('Alegeți serviciul, ziua și ora')->assertSee('Consultație test')
            ->assertDontSee('Ședință AlterG test', false);

        $day = $this->day();
        // Un interval ocupat nu se mai oferă.
        app(BookingService::class)->book(new BookingRequest($this->patient()->id, $this->consult->id, $this->medic->id, $this->location->id, $day->setTime(9, 0)), null);
        Cache::flush();

        $html = (string) $this->get('/programare-online?serviciu='.$this->consult->id.'&zi='.$day->toDateString())
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')->getContent();
        $this->assertStringContainsString('ora=08%3A30', $html);
        $this->assertStringNotContainsString('ora=09%3A00', $html, 'intervalul rezervat nu apare');
        $this->assertStringNotContainsString('ora=12%3A00', $html, 'pauza nu apare');
        $this->assertStringContainsString('dr. Test Medic', $html, 'specialistul afișat public se poate alege');

        $this->get('/programare-online?serviciu='.$this->consult->id.'&zi='.$day->toDateString().'&ora=10:00')
            ->assertOk()->assertSee('Datele dumneavoastră')->assertSee('ora 10:00')->assertSee('name="'.FormToken::FIELD.'"', false);
    }

    public function test_submission_rechecks_the_slot_and_creates_a_request(): void
    {
        $start = $this->day()->setTime(10, 0);
        $this->post('/programare-online', $this->payload($start))->assertStatus(303)->assertRedirectContains('/contactus-thank-you?confirmare=');

        $request = Appointment::where('source', 'site')->sole();
        $this->assertSame(AppointmentStatus::SOLICITARE, $request->status, 'fără confirmare automată rămâne solicitare');
        $this->assertSame($this->consult->id, $request->service_id);
        $this->assertSame($this->medic->id, $request->staff_member_id);
        $this->assertTrue($request->request_preferred_start->equalTo($start));

        // Un interval ocupat între timp e refuzat, cu mesaj, fără a pretinde succesul.
        app(BookingService::class)->book(new BookingRequest($this->patient()->id, $this->consult->id, $this->medic->id, $this->location->id, $this->day()->setTime(11, 0)), null);
        Cache::flush();
        $this->post('/programare-online', $this->payload($this->day()->setTime(11, 0)))->assertStatus(422)->assertSee('tocmai a fost ocupat');
        $this->assertSame(1, Appointment::where('source', 'site')->count());
    }

    public function test_auto_confirmation_follows_clinic_rules(): void
    {
        app(Settings::class)->set('scheduling.online.auto_confirm', true);
        $this->consult->update(['online_auto_confirm' => true]);
        $start = $this->day()->setTime(14, 0);

        $response = $this->post('/programare-online', $this->payload($start));
        $response->assertStatus(303);
        $appointment = Appointment::where('source', 'site')->sole();
        $this->assertSame(AppointmentStatus::CONFIRMATA, $appointment->status);
        $this->assertTrue($appointment->starts_at->equalTo($start));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('Programarea a fost confirmată pentru', \Illuminate\Support\Facades\Crypt::decryptString($q['confirmare']));
    }

    public function test_validation_spam_and_privacy(): void
    {
        $start = $this->day()->setTime(10, 0);
        $this->post('/programare-online', $this->payload($start, ['name' => '', 'consent' => '0']))
            ->assertStatus(422)->assertSee('Completați numele.')->assertSee('necesar acordul');
        // Prea repede: nu se pretinde o programare reușită.
        $this->post('/programare-online', $this->payload($start, [FormToken::FIELD => FormToken::issue('programare')]))
            ->assertStatus(422)->assertSee('apăsați din nou');
        // Câmpul-capcană: răspuns neutru, nimic salvat.
        $this->post('/programare-online', $this->payload($start, [FormToken::HONEYPOT => 'http://spam.example']))->assertStatus(303);
        $this->assertSame(0, Appointment::where('source', 'site')->count());
        // Datele introduse nu ajung în URL la erori.
        $r = $this->post('/programare-online', $this->payload($start, ['phone' => 'x']));
        $r->assertStatus(422)->assertSee('Pacient Demo Fictiv');
        $this->assertNull($r->headers->get('Location'));
        // Pagina nu setează cookie de sesiune.
        $this->assertEmpty($this->get('/programare-online')->headers->getCookies());
    }

    public function test_opening_hours_and_disabled_online_booking(): void
    {
        // Programul public al locației limitează orele oferite online (personalul lucrează și sâmbăta în fixture).
        $this->location->update(['opening_hours' => ['1' => [['08:00', '16:00']], '2' => [['08:00', '16:00']], '3' => [['08:00', '16:00']], '4' => [['08:00', '16:00']], '5' => [['08:00', '16:00']]]]);
        $booking = app(PublicBooking::class);
        $saturday = CarbonImmutable::today()->addWeek()->next(CarbonImmutable::SATURDAY);
        $this->assertSame([], $booking->slots($this->consult->id, $this->location->id, $saturday));
        $monday = $saturday->addDays(2);
        $slots = $booking->slots($this->consult->id, $this->location->id, $monday);
        $this->assertSame('15:30', end($slots)['start']->format('H:i'), 'ultima consultație de 30 min se termină la închidere');

        app(Settings::class)->set('scheduling.online.enabled', false);
        $this->get('/programare-online')->assertOk()->assertSee('Programarea online va fi disponibilă în curând');
        $this->assertStringNotContainsString('/programare-online', (string) $this->get('/sitemap.xml')->getContent());
    }
}
