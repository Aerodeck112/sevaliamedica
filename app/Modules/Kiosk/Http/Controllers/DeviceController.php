<?php

namespace App\Modules\Kiosk\Http\Controllers;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Location;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Services\KioskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Settings $settings): View
    {
        return view('kiosk::panel.devices', [
            'devices' => KioskDevice::with('location')->orderBy('name')->get(),
            'locations' => Location::active()->pluck('name', 'id')->all(),
            'googleReviewUrl' => $settings->get('kiosk.google_review_url'),
            'codeMinutes' => $settings->get('kiosk.code_minutes', 20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', Rule::in(array_keys(KioskDevice::MODES))],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);
        $device = KioskDevice::create($data + ['created_by' => $request->user()->id]);
        Audit::log('kiosk.device_created', $device);

        return $this->issuePairing($device);
    }

    public function pairing(KioskDevice $device): RedirectResponse
    {
        abort_if($device->revoked_at !== null, 422, 'Tableta este revocată.');

        return $this->issuePairing($device);
    }

    public function revoke(KioskDevice $device): RedirectResponse
    {
        $device->forceFill(['revoked_at' => now(), 'token_hash' => null, 'pairing_code_hash' => null])->save();
        Audit::log('kiosk.device_revoked', $device);

        return back()->with('status', "Tableta „{$device->name}” a fost deconectată. Pentru a o folosi din nou, adăugați-o ca tabletă nouă.");
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'google_review_url' => ['nullable', 'url', 'max:500', 'regex:#^https://#'],
            'code_minutes' => ['required', 'integer', 'min:2', 'max:120'],
        ]);
        $settings->set('kiosk.google_review_url', $data['google_review_url'] ?: null);
        $settings->set('kiosk.code_minutes', (int) $data['code_minutes']);
        Audit::log('kiosk.settings_updated', context: ['google' => (bool) $data['google_review_url'], 'minute' => (int) $data['code_minutes']]);

        return back()->with('status', 'Setările tabletelor au fost salvate.');
    }

    private function issuePairing(KioskDevice $device): RedirectResponse
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $device->forceFill(['pairing_code_hash' => KioskService::hashCode($code), 'pairing_expires_at' => now()->addMinutes(30)])->save();
        Audit::log('kiosk.pairing_issued', $device);

        return redirect()->route('panou.kiosk.devices')->with('pairing', ['device' => $device->name, 'code' => substr($code, 0, 4).'-'.substr($code, 4)]);
    }
}
