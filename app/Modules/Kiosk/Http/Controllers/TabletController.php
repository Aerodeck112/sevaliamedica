<?php

namespace App\Modules\Kiosk\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Http\Middleware\KioskDeviceAuth;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Models\KioskSession;
use App\Modules\Kiosk\Services\KioskService;
use App\Core\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Ecranele tabletei de la recepție (fără cont de utilizator; tableta e identificată prin tokenul de dispozitiv). */
class TabletController extends Controller
{
    public function __construct(private readonly KioskService $kiosk) {}

    public function showPair(Request $request): View|RedirectResponse
    {
        return view('kiosk::tablet.pair');
    }

    public function pair(Request $request): RedirectResponse
    {
        $data = $request->validate(['pairing_code' => ['required', 'string', 'max:20']]);
        $key = 'kiosk-pair:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return back()->withErrors(['pairing_code' => 'Prea multe încercări. Reîncercați peste câteva minute.']);
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['pairing_code']));
        $device = KioskDevice::where('pairing_code_hash', KioskService::hashCode($code))->whereNull('revoked_at')
            ->where('pairing_expires_at', '>', now())->first();
        if (! $device) {
            RateLimiter::hit($key, 600);

            return back()->withErrors(['pairing_code' => 'Codul de asociere nu este valid sau a expirat. Generați unul nou din panou.']);
        }
        $token = Str::random(64);
        $device->forceFill(['token_hash' => KioskService::hashToken($token), 'pairing_code_hash' => null, 'pairing_expires_at' => null, 'paired_at' => now(), 'last_seen_at' => now()])->save();
        Audit::log('kiosk.device_paired', $device);

        return redirect()->route('kiosk.home')->withCookie(cookie(KioskDeviceAuth::COOKIE, $token, 60 * 24 * 365 * 3, '/tableta', null, $request->isSecure(), true, false, 'strict'));
    }

    public function home(Request $request): View
    {
        return view('kiosk::tablet.idle', ['device' => $this->device($request), 'clinic' => app(Settings::class)->get('site.name', 'SEVALIA')]);
    }

    public function enterCode(Request $request): RedirectResponse
    {
        $device = $this->device($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $key = 'kiosk-code:'.$device->id;
        if (RateLimiter::tooManyAttempts($key, KioskService::MAX_ATTEMPTS)) {
            return back()->withErrors(['code' => 'Prea multe coduri greșite. Tableta se deblochează în '.ceil(RateLimiter::availableIn($key) / 60).' minute.']);
        }
        try {
            $session = $this->kiosk->openByCode($device, $data['code']);
        } catch (ValidationException $e) {
            RateLimiter::hit($key, 300);
            throw $e;
        }
        RateLimiter::clear($key);
        return redirect()->route('kiosk.request');
    }

    /** Tableta întreabă periodic dacă recepția i-a trimis direct o recenzie (fără cod). */
    public function poll(Request $request): JsonResponse
    {
        $session = $this->kiosk->pendingAutoOpen($this->device($request));
        if ($session) {
            return response()->json(['open' => route('kiosk.request')]);
        }

        return response()->json(['open' => null]);
    }

    public function showRequest(Request $request): View|RedirectResponse
    {
        $session = $this->current($request);
        if (! $session) {
            return redirect()->route('kiosk.home');
        }

        return view($session->kind === 'consimtamant' ? 'kiosk::tablet.consent' : 'kiosk::tablet.review', [
            'session' => $session->load(['consentVersion.template', 'staffMember', 'service', 'patient']),
            'googleReviewUrl' => app(Settings::class)->get('kiosk.google_review_url'),
        ]);
    }

    public function consent(Request $request): RedirectResponse
    {
        $session = $this->current($request) ?? abort(410);
        $device = $this->device($request);
        if ($request->input('action') === 'refuz') {
            $this->kiosk->refuseConsent($session, $device);

            return redirect()->route('kiosk.thanks', ['tip' => 'refuz']);
        }
        $data = $request->validate([
            'agree' => ['accepted'],
            'signature' => ['required', 'string', 'max:900000'],
            'representative_name' => ['nullable', 'string', 'max:160'],
        ], ['agree.accepted' => 'Pentru a semna, bifați că ați citit și sunteți de acord.', 'signature.required' => 'Semnați în chenar.']);
        $this->kiosk->signConsent($session, $device, $data['signature'], $data['representative_name'] ?? null);

        return redirect()->route('kiosk.thanks', ['tip' => 'consimtamant']);
    }

    public function review(Request $request): RedirectResponse
    {
        $session = $this->current($request) ?? abort(410);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'publish_consent' => ['nullable', 'boolean'],
        ], ['rating.required' => 'Alegeți numărul de stele.']);
        $this->kiosk->submitReview($session, $this->device($request), (int) $data['rating'], $data['comment'] ?? null, (bool) ($data['publish_consent'] ?? false));

        return redirect()->route('kiosk.thanks', ['tip' => 'recenzie']);
    }

    public function thanks(Request $request): View
    {
        return view('kiosk::tablet.thanks', ['kind' => $request->query('tip'), 'googleReviewUrl' => app(Settings::class)->get('kiosk.google_review_url')]);
    }

    private function device(Request $request): KioskDevice
    {
        return $request->attributes->get('kiosk_device');
    }

    /** Cererea deschisă acum pe această tabletă (una singură), independent de sesiunea browserului. */
    private function current(Request $request): ?KioskSession
    {
        return KioskSession::where('opened_on_device_id', $this->device($request)->id)->where('status', 'deschis')
            ->where('expires_at', '>', now())->latest('opened_at')->first();
    }
}
