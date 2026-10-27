<?php

namespace App\Modules\Site\Http\Controllers;

use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\PublicAppointmentRequest;
use App\Core\Contracts\PublicBooking;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Location;
use App\Modules\Site\Services\FormSubmissionIntake;
use App\Modules\Site\Services\FormToken;
use App\Modules\Site\Services\SiteView;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Programarea online: serviciu → locație → specialist (opțional) → zi → oră → date de contact.
 * Fiecare pas e un link (funcționează fără JavaScript și fără sesiune). La trimitere intervalul se reverifică,
 * iar AppointmentIntake decide după regulile clinicii dacă îl confirmă sau îl înregistrează ca solicitare.
 */
class BookingController extends Controller
{
    public const FORM = 'programare';

    /** Erorile și datele introduse, pentru reafișarea formularului în aceeași cerere. */
    private ?array $flash = null;

    public function __construct(private readonly PublicBooking $booking, private readonly SiteView $view) {}

    public function show(Request $request): Response
    {
        $services = $this->booking->available() ? $this->booking->services() : [];
        $state = $this->state($request, $services);

        $days = $slots = [];
        if ($state['service'] && $state['location']) {
            $days = $this->booking->days($state['service']['id'], $state['location'], $state['staff']);
            if ($state['day'] && ! in_array($state['day'], $days, true)) {
                $state['day'] = null;
            }
            $state['day'] ??= $days[0] ?? null;
            if ($state['day']) {
                $slots = $this->booking->slots($state['service']['id'], $state['location'], CarbonImmutable::parse($state['day']), $state['staff']);
            }
        }
        $slot = null;
        if ($state['time']) {
            $slot = collect($slots)->first(fn ($s) => $s['start']->format('H:i') === $state['time']
                && ($state['staff'] === null || $s['staff_member_id'] === $state['staff']));
        }

        $response = response($this->view->standalone('site::booking', [
            'available' => $services !== [],
            'services' => $services,
            'state' => $state,
            'days' => $days,
            'slots' => $slots,
            'slot' => $slot,
            'phone' => Location::active()->value('phone'),
            'token' => FormToken::issue(self::FORM),
            'formErrors' => new MessageBag($this->flash['errors'] ?? []),
            'old' => $this->flash['old'] ?? [],
        ], $request, [
            'title' => 'Programare online | SEVALIA Târgu Mureș',
            'description' => 'Programați-vă online la Clinica SEVALIA din Târgu Mureș: alegeți serviciul, ziua și ora libere din calendarul clinicii.',
        ])->render());
        $response->headers->set('Cache-Control', 'no-store');
        // Pașii intermediari (cu parametri) nu se indexează; pagina de start da.
        if ($request->query() !== [] || $this->flash) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    public function submit(Request $request, AppointmentIntake $intake): Response
    {
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');
        if ($origin && parse_url($origin, PHP_URL_HOST) !== $request->getHost()) {
            abort(403, 'Cerere respinsă.');
        }
        $problem = FormToken::verify(self::FORM, $request->input(FormToken::FIELD));
        if ($problem === 'token' || $problem === 'expirat') {
            return $this->back($request, ['form' => 'Pagina a expirat. Alegeți din nou intervalul.']);
        }
        if (filled($request->input(FormToken::HONEYPOT))) {
            Log::info('Programare online respinsă ca spam', ['motiv' => 'capcana']);

            return redirect(FormController::SUCCESS_PAGE, 303);
        }
        // Trimis prea repede după afișare: nu pretindem o programare reușită, cerem retrimiterea.
        if ($problem === 'prea_rapid') {
            return $this->back($request, ['form' => 'Vă rugăm să verificați datele și să apăsați din nou „Trimite programarea”.']);
        }
        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $limiter = 'site-booking:'.$ipHash;
        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            return $this->back($request, ['form' => 'Ați trimis deja mai multe programări. Reîncercați peste câteva minute sau sunați-ne.']);
        }

        $validator = Validator::make($request->all(), [
            'service' => ['required', 'integer'],
            'location' => ['required', 'integer'],
            'staff' => ['nullable', 'integer'],
            'start' => ['required', 'date'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[0-9 +().\-]{9,20}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'contact' => ['required', Rule::in(['telefon', 'sms', 'email'])],
            'message' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ], [
            'name.required' => 'Completați numele.',
            'phone.required' => 'Completați numărul de telefon.',
            'phone.regex' => 'Numărul de telefon nu pare valid.',
            'email.email' => 'Adresa de e-mail nu pare validă.',
            'consent.accepted' => 'Pentru programare este necesar acordul pentru prelucrarea datelor.',
        ]);
        if ($validator->fails()) {
            return $this->back($request, $validator->errors()->toArray());
        }
        $data = $validator->validated();
        if ($data['contact'] === 'email' && empty($data['email'])) {
            return $this->back($request, ['email' => 'Completați adresa de e-mail sau alegeți alt mod de contact.']);
        }
        RateLimiter::hit($limiter, 600);

        // Intervalul se reverifică acum: între timp putea fi ocupat.
        $start = CarbonImmutable::parse($data['start']);
        $staffId = isset($data['staff']) ? (int) $data['staff'] : null;
        $slot = collect($this->booking->slots((int) $data['service'], (int) $data['location'], $start->startOfDay(), $staffId))
            ->first(fn ($s) => $s['start']->equalTo($start));
        if (! $slot) {
            return $this->back($request, ['start' => 'Intervalul ales tocmai a fost ocupat. Alegeți altă oră.'], withTime: false);
        }

        $requestData = new PublicAppointmentRequest(
            name: $data['name'], phone: $data['phone'], email: $data['email'] ?? null, preferredContact: $data['contact'],
            specialtyId: null, staffMemberId: $slot['staff_member_id'], serviceId: (int) $data['service'], locationId: (int) $data['location'],
            preferredStart: $start, message: $data['message'] ?? null, birthDate: null,
            consentAt: CarbonImmutable::now(), sourceUrl: '/programare-online', ipHash: $ipHash,
        );
        $result = $intake->submit($requestData);
        if (! $result->accepted) {
            $result = app(FormSubmissionIntake::class)->submit($requestData);
        }

        return redirect(FormController::SUCCESS_PAGE.'?confirmare='.urlencode(Crypt::encryptString($result->publicMessage)), 303);
    }

    /** Starea pașilor din parametrii URL, validată față de serviciile disponibile. */
    private function state(Request $request, array $services): array
    {
        $service = collect($services)->firstWhere('id', (int) $request->query('serviciu'));
        $location = null;
        $staff = null;
        if ($service) {
            $location = array_key_exists((int) $request->query('locatie'), $service['locations']) ? (int) $request->query('locatie')
                : (count($service['locations']) === 1 ? array_key_first($service['locations']) : null);
            $staff = array_key_exists((int) $request->query('specialist'), $service['staff']) ? (int) $request->query('specialist') : null;
        }
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('zi')) ? (string) $request->query('zi') : null;
        $time = preg_match('/^\d{2}:\d{2}$/', (string) $request->query('ora')) ? (string) $request->query('ora') : null;

        return compact('service', 'location', 'staff', 'day', 'time');
    }

    /** Reafișează pasul de date cu erorile, direct (fără redirect: datele introduse nu ajung în URL). */
    private function back(Request $request, array $errors, bool $withTime = true): Response
    {
        $start = CarbonImmutable::make($request->input('start'));
        $query = array_filter([
            'serviciu' => $request->input('service'), 'locatie' => $request->input('location'), 'specialist' => $request->input('staff'),
            'zi' => $start?->toDateString(), 'ora' => $withTime ? $start?->format('H:i') : null,
        ]);
        $this->flash = ['errors' => $errors, 'old' => $request->only(['name', 'phone', 'email', 'contact', 'message', 'consent'])];
        $sub = Request::create('/programare-online', 'GET', $query);
        $sub->headers->set('host', $request->getHost());

        return $this->show($sub)->setStatusCode(422);
    }
}
