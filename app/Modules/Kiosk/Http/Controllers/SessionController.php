<?php

namespace App\Modules\Kiosk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Models\KioskSession;
use App\Modules\Kiosk\Services\KioskService;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\ServiceRecord;
use App\Core\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Trimiterea cererilor pe tabletă din fișa pacientului. Codul de 6 cifre se afișează o singură dată. */
class SessionController extends Controller
{
    public function __construct(private readonly KioskService $kiosk) {}

    public function storeConsent(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate([
            'consent_template_version_id' => ['required', 'integer', 'exists:consent_template_versions,id'],
            'kiosk_device_id' => ['nullable', 'integer', 'exists:kiosk_devices,id'],
        ]);
        $version = ConsentTemplateVersion::findOrFail($data['consent_template_version_id']);
        $device = $this->device($data['kiosk_device_id'] ?? null, 'consimtamant');
        [$session, $code] = $this->kiosk->createConsentSession($patient, $version, $request->user(), $device);

        return back()->with('kiosk_code', ['code' => $code, 'kind' => 'consimtamant', 'expires' => $session->expires_at->format('H:i')]);
    }

    public function storeReview(Request $request, ServiceRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'kiosk_device_id' => ['nullable', 'integer', 'exists:kiosk_devices,id'],
            'auto_open' => ['nullable', 'boolean'],
        ]);
        $device = $this->device($data['kiosk_device_id'] ?? null, 'recenzie');
        [$session, $code] = $this->kiosk->createReviewSession($record, $request->user(), $device, (bool) ($data['auto_open'] ?? false));

        return back()->with('kiosk_code', ['code' => $code, 'kind' => 'recenzie', 'expires' => $session->expires_at->format('H:i'), 'auto' => $session->auto_open]);
    }

    public function cancel(KioskSession $session): RedirectResponse
    {
        if (in_array($session->status, ['in_asteptare', 'deschis'], true)) {
            $session->forceFill(['status' => 'anulat'])->save();
            Audit::log('kiosk.session_cancelled', $session, patientId: $session->patient_id);
        }

        return back()->with('status', 'Cererea de pe tabletă a fost anulată.');
    }

    private function device(?int $id, string $kind): ?KioskDevice
    {
        if (! $id) {
            return null;
        }
        $device = KioskDevice::whereNull('revoked_at')->findOrFail($id);
        abort_unless($device->accepts($kind), 422, 'Tableta aleasă nu este configurată pentru acest tip de cerere.');

        return $device;
    }
}
