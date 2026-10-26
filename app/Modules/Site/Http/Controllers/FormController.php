<?php

namespace App\Modules\Site\Http\Controllers;

use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\PublicAppointmentRequest;
use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\Page;
use App\Modules\Site\Services\FormBlock;
use App\Modules\Site\Services\FormSubmissionIntake;
use App\Modules\Site\Services\FormToken;
use App\Modules\Site\Services\SiteView;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Primește formularele publice. Nu folosește sesiune: protecția se face prin token semnat cu marcaj de timp,
 * verificarea originii, câmp-capcană și limitarea numărului de trimiteri pe IP (hash).
 */
class FormController extends Controller
{
    public const SOURCE_PAGES = ['contact' => '/contactus', 'emsella' => '/emsella-landing-oferta-vara'];

    public const SUCCESS_PAGE = '/contactus-thank-you';

    public function __construct(private readonly Settings $settings) {}

    public function submit(Request $request, string $form, AppointmentIntake $intake, FormBlock $forms, SiteView $view): BaseResponse
    {
        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        if (! $this->sameOrigin($request)) {
            abort(403, 'Cerere respinsă.');
        }
        $tokenProblem = FormToken::verify($form, $request->input(FormToken::FIELD));
        if ($tokenProblem === 'token' || $tokenProblem === 'expirat') {
            return $this->invalid($request, $form, $forms, $view, ['form' => 'Formularul a expirat. Vă rugăm să îl completați din nou.']);
        }

        // Roboții: câmp-capcană completat sau trimitere instantanee. Răspundem neutru, fără a salva nimic.
        if (filled($request->input(FormToken::HONEYPOT)) || $tokenProblem === 'prea_rapid') {
            Log::info('Formular public respins ca spam', ['form' => $form, 'motiv' => $tokenProblem ?? 'capcana']);

            return redirect(self::SUCCESS_PAGE, 303);
        }

        $limiterKey = 'site-form:'.$ipHash;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return $this->invalid($request, $form, $forms, $view, ['form' => 'Ați trimis deja mai multe mesaje. Vă rugăm să reîncercați peste câteva minute sau să ne sunați.'], 429);
        }

        $validator = Validator::make($request->all(), $this->rules($form, $request), $this->messages());
        if ($validator->fails()) {
            return $this->invalid($request, $form, $forms, $view, $validator->errors()->toArray());
        }
        RateLimiter::hit($limiterKey, 600);
        $data = $validator->validated();

        $contact = (array) ($data['contact'] ?? []);
        $phone = $data['phone'] ?? $data['phone_sms'] ?? null;
        $notes = [];
        if (! empty($data['subject'])) {
            $notes[] = 'Subiect: '.$data['subject'];
        }
        if (count($data['specialties'] ?? []) > 1) {
            $notes[] = 'Specialități: '.Specialty::whereIn('id', $data['specialties'])->pluck('name')->join(', ');
        }
        if (count($data['doctors'] ?? []) > 1) {
            $notes[] = 'Medici: '.StaffMember::whereIn('id', $data['doctors'])->pluck('display_name')->join(', ');
        }
        if (count($contact) > 1) {
            $notes[] = 'Contact acceptat prin: '.implode(', ', $contact);
        }
        if (! empty($data['message'])) {
            $notes[] = $data['message'];
        }

        $requestData = new PublicAppointmentRequest(
            name: $data['name'],
            phone: $phone,
            email: $data['email'] ?? null,
            preferredContact: $contact[0] ?? null,
            specialtyId: isset($data['specialties'][0]) ? (int) $data['specialties'][0] : null,
            staffMemberId: isset($data['doctors'][0]) ? (int) $data['doctors'][0] : null,
            serviceId: null,
            locationId: null,
            preferredStart: null,
            message: $notes ? implode("\n", $notes) : null,
            birthDate: $data['birth_date'] ?? null,
            consentAt: CarbonImmutable::now(),
            sourceUrl: self::SOURCE_PAGES[$form],
            ipHash: $ipHash,
        );
        $result = $intake->submit($requestData);
        // Dacă CRM-ul nu preia solicitarea (ex. solicitările online sunt oprite), mesajul nu se pierde:
        // se păstrează în „Mesaje din site”, de unde recepția îl poate contacta.
        if (! $result->accepted) {
            $result = app(FormSubmissionIntake::class)->submit($requestData);
        }

        $target = self::SUCCESS_PAGE;
        // Mesajul paginii de confirmare nu promite un interval; doar o rezervare reală adaugă detaliul primit.
        if ($result->confirmed) {
            $target .= '?confirmare='.urlencode(Crypt::encryptString($result->publicMessage));
        }

        return redirect($target, 303);
    }

    private function rules(string $form, Request $request): array
    {
        $contact = (array) $request->input('contact', []);
        $needsPhone = in_array('telefon', $contact, true) || in_array('sms', $contact, true);
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'contact' => ['required', 'array', 'min:1'],
            'contact.*' => [Rule::in(['telefon', 'email', 'sms'])],
            'phone' => [$needsPhone && ! $request->filled('phone_sms') ? 'required' : 'nullable', 'string', 'regex:/^[0-9 +().\-]{9,20}$/'],
            'phone_sms' => ['nullable', 'string', 'regex:/^[0-9 +().\-]{9,20}$/'],
            'email' => [in_array('email', $contact, true) ? 'required' : 'nullable', 'email:rfc', 'max:190'],
            'consent' => ['accepted'],
        ];
        if ($form === 'contact') {
            $rules['specialties'] = ['nullable', 'array'];
            $rules['specialties.*'] = ['integer', Rule::exists('specialties', 'id')->where('is_active', true)];
            $rules['doctors'] = ['nullable', 'array'];
            $rules['doctors.*'] = ['integer', Rule::exists('staff_members', 'id')->where('show_on_site', true)];
            if ($this->settings->get('site.contact_birthdate', false)) {
                $rules['birth_date'] = ['nullable', 'string', 'max:20'];
            }
        } else {
            $rules['subject'] = ['nullable', 'string', 'max:190'];
            $rules['message'] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Completați numele.',
            'name.min' => 'Completați numele.',
            'contact.required' => 'Alegeți cel puțin o modalitate de contact.',
            'phone.required' => 'Completați numărul de telefon.',
            'phone.regex' => 'Numărul de telefon nu pare corect.',
            'phone_sms.regex' => 'Numărul de telefon nu pare corect.',
            'email.required' => 'Completați adresa de e-mail.',
            'email.email' => 'Adresa de e-mail nu pare corectă.',
            'consent.accepted' => 'Pentru a trimite mesajul este necesar acordul cu Politica de confidențialitate și Termenii și condițiile.',
        ];
    }

    /** Reafișează pagina formularului cu erorile și valorile introduse (fără sesiune, fără redirect). */
    private function invalid(Request $request, string $form, FormBlock $forms, SiteView $view, array $errors, int $status = 422): BaseResponse
    {
        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag($errors));
        $forms->withState($bag, $request->except([FormToken::FIELD, FormToken::HONEYPOT]));
        $page = Page::published()->where('path', self::SOURCE_PAGES[$form])->first();
        abort_unless($page, 404);

        return new Response($view->page($page, $page->publishedVersion, $request)->render(), $status, ['Cache-Control' => 'no-store']);
    }

    private function sameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (! $origin) {
            return true; // unele browsere/proxy-uri nu trimit antetele; tokenul semnat rămâne obligatoriu
        }

        return parse_url($origin, PHP_URL_HOST) === $request->getHost();
    }
}
