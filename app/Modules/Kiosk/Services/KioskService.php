<?php

namespace App\Modules\Kiosk\Services;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Models\User;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Models\KioskSession;
use App\Modules\Kiosk\Models\PatientReview;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Medical\Models\MedicalDocument;
use App\Modules\Medical\Models\PatientConsent;
use App\Modules\Medical\Services\DocumentStorage;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fluxurile tabletelor de la recepție:
 *  - consimțământ: personalul trimite un model de consimțământ → pacientul introduce codul de 6 cifre pe tabletă →
 *    citește textul, bifează acordul și semnează pe ecran (semnătură electronică simplă) sau refuză;
 *  - recenzie: după un serviciu efectuat → pacientul acordă 1–5 stele și poate lăsa un comentariu.
 *
 * Codurile se păstrează doar ca HMAC, expiră și se blochează după încercări greșite. Tableta nu afișează date
 * medicale; pentru confirmarea identității arată doar prenumele și inițiala numelui.
 */
class KioskService
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Settings $settings, private readonly DocumentStorage $storage) {}

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', 'kiosk|'.preg_replace('/\D/', '', $code), (string) config('app.key'));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{0: KioskSession, 1: string} sesiunea și codul în clar (afișat o singură dată personalului) */
    public function createConsentSession(Patient $patient, ConsentTemplateVersion $version, User $by, ?KioskDevice $device = null): array
    {
        return $this->create([
            'kind' => 'consimtamant',
            'patient_id' => $patient->id,
            'consent_template_version_id' => $version->id,
            'kiosk_device_id' => $device?->id,
        ], $by);
    }

    /** @return array{0: KioskSession, 1: string} */
    public function createReviewSession(ServiceRecord $record, User $by, ?KioskDevice $device = null, bool $autoOpen = false): array
    {
        if ($record->status !== 'efectuat') {
            throw ValidationException::withMessages(['service_record' => 'Recenzia se poate cere doar pentru un serviciu efectuat.']);
        }
        if (PatientReview::where('service_record_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['service_record' => 'Pentru acest serviciu există deja o recenzie.']);
        }

        return $this->create([
            'kind' => 'recenzie',
            'patient_id' => $record->patient_id,
            'service_record_id' => $record->id,
            'staff_member_id' => $record->staff_member_id,
            'service_id' => $record->service_id,
            'kiosk_device_id' => $device?->id,
            'auto_open' => $autoOpen && $device !== null,
        ], $by);
    }

    private function create(array $data, User $by): array
    {
        return DB::transaction(function () use ($data, $by) {
            // Cererile anterioare încă deschise pentru același pacient și tip se anulează: o singură cerere activă.
            KioskSession::where('patient_id', $data['patient_id'])->where('kind', $data['kind'])
                ->whereIn('status', ['in_asteptare', 'deschis'])->update(['status' => 'anulat']);
            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = self::hashCode($code);
            } while (KioskSession::where('code_hash', $hash)->whereIn('status', ['in_asteptare', 'deschis'])->where('expires_at', '>', now())->exists());

            $minutes = (int) $this->settings->get('kiosk.code_minutes', 20);
            $session = KioskSession::create($data + [
                'code_hash' => $hash,
                'status' => 'in_asteptare',
                'expires_at' => now()->addMinutes(max(2, $minutes)),
                'created_by' => $by->id,
            ]);
            Audit::log('kiosk.session_created', $session, ['tip' => $data['kind']], patientId: $data['patient_id']);

            return [$session, $code];
        });
    }

    /** Deschide pe tabletă cererea corespunzătoare codului. Erorile nu dezvăluie dacă un cod există pentru alt tip. */
    public function openByCode(KioskDevice $device, string $code): KioskSession
    {
        $hash = self::hashCode($code);
        $session = KioskSession::where('code_hash', $hash)->whereIn('status', ['in_asteptare', 'deschis'])->latest('id')->first();
        if (! $session || ! $session->isOpenable() || ! $device->accepts($session->kind)
            || ($session->kiosk_device_id !== null && $session->kiosk_device_id !== $device->id)) {
            Audit::log('kiosk.code_rejected', $device);
            throw ValidationException::withMessages(['code' => 'Codul nu este valid sau a expirat. Cereți un cod nou la recepție.']);
        }
        $this->closeOthersOn($device, $session);
        $session->forceFill(['status' => 'deschis', 'opened_at' => now(), 'opened_on_device_id' => $device->id])->save();
        Audit::log('kiosk.session_opened', $session, ['tableta' => $device->id], patientId: $session->patient_id);

        return $session;
    }

    /** O tabletă are o singură cerere deschisă: celelalte revin „în așteptare” (pot fi redeschise cu codul lor). */
    private function closeOthersOn(KioskDevice $device, KioskSession $except): void
    {
        KioskSession::where('opened_on_device_id', $device->id)->where('status', 'deschis')->whereKeyNot($except->id)
            ->update(['status' => 'in_asteptare', 'opened_on_device_id' => null]);
    }

    /** Recenziile trimise direct pe o tabletă (fără cod) se deschid singure când tableta întreabă. */
    public function pendingAutoOpen(KioskDevice $device): ?KioskSession
    {
        $session = KioskSession::where('kiosk_device_id', $device->id)->where('auto_open', true)
            ->where('status', 'in_asteptare')->where('expires_at', '>', now())->oldest('id')->first();
        if ($session) {
            $this->closeOthersOn($device, $session);
            $session->forceFill(['status' => 'deschis', 'opened_at' => now(), 'opened_on_device_id' => $device->id])->save();
            Audit::log('kiosk.session_opened', $session, ['tableta' => $device->id, 'automat' => true], patientId: $session->patient_id);
        }

        return $session;
    }

    public function signConsent(KioskSession $session, KioskDevice $device, string $signaturePng, ?string $representativeName = null): PatientConsent
    {
        $this->assertOpenOn($session, $device, 'consimtamant');
        $png = $this->validSignature($signaturePng);
        $version = $session->consentVersion()->with('template')->firstOrFail();

        return DB::transaction(function () use ($session, $device, $png, $version, $representativeName) {
            $stored = $this->storage->storeBytes($png, 'image/png', 'semnatura-consimtamant-'.$session->id.'.png');
            $document = MedicalDocument::create($stored + [
                'patient_id' => $session->patient_id,
                'title' => 'Semnătură pe tabletă — '.$version->template->name.' (v'.$version->version.')',
                'category' => 'consimtamant',
                'uploaded_by' => $session->created_by,
            ]);
            $consent = PatientConsent::create([
                'patient_id' => $session->patient_id,
                'consent_template_version_id' => $version->id,
                'method' => 'semnat_tableta',
                'given_by' => $representativeName ? 'reprezentant' : 'pacient',
                'representative_name' => $representativeName ?: null,
                'given_at' => now(),
                'collected_by' => $session->created_by,
                'medical_document_id' => $document->id,
                'notes' => sprintf('Semnătură electronică simplă pe tableta „%s” (#%d), sesiunea #%d. Amprenta textului semnat (SHA-256): %s. Amprenta semnăturii: %s.',
                    $device->name, $device->id, $session->id, hash('sha256', (string) $version->body), $stored['sha256']),
            ]);
            $session->forceFill(['status' => 'finalizat', 'completed_at' => now(), 'patient_consent_id' => $consent->id])->save();
            Audit::log('kiosk.consent_signed', $consent, ['sesiune' => $session->id, 'tableta' => $device->id], patientId: $session->patient_id);

            return $consent;
        });
    }

    public function refuseConsent(KioskSession $session, KioskDevice $device): void
    {
        $this->assertOpenOn($session, $device, 'consimtamant');
        $session->forceFill(['status' => 'refuzat', 'completed_at' => now()])->save();
        Audit::log('kiosk.consent_refused', $session, ['tableta' => $device->id], patientId: $session->patient_id);
    }

    public function submitReview(KioskSession $session, KioskDevice $device, int $rating, ?string $comment, bool $publishConsent): PatientReview
    {
        $this->assertOpenOn($session, $device, 'recenzie');
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => 'Alegeți între 1 și 5 stele.']);
        }

        return DB::transaction(function () use ($session, $device, $rating, $comment, $publishConsent) {
            $review = PatientReview::create([
                'patient_id' => $session->patient_id,
                'kiosk_session_id' => $session->id,
                'service_record_id' => $session->service_record_id,
                'service_id' => $session->service_id,
                'staff_member_id' => $session->staff_member_id,
                'rating' => $rating,
                'comment' => ($c = trim((string) $comment)) !== '' ? mb_substr($c, 0, 2000) : null,
                'publish_consent' => $publishConsent,
                'source' => 'tableta',
            ]);
            $session->forceFill(['status' => 'finalizat', 'completed_at' => now()])->save();
            Audit::log('kiosk.review_submitted', $review, ['stele' => $rating, 'tableta' => $device->id], patientId: $session->patient_id);

            return $review;
        });
    }

    private function assertOpenOn(KioskSession $session, KioskDevice $device, string $kind): void
    {
        if ($session->kind !== $kind || $session->status !== 'deschis' || $session->opened_on_device_id !== $device->id || $session->expires_at->isPast()) {
            throw ValidationException::withMessages(['session' => 'Cererea nu mai este deschisă pe această tabletă. Cereți un cod nou la recepție.']);
        }
    }

    /** Semnătura trebuie să fie o imagine PNG reală, de dimensiuni rezonabile și cu trasee desenate. */
    private function validSignature(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw ValidationException::withMessages(['signature' => 'Semnătura lipsește. Semnați în chenar.']);
        }
        $png = (string) base64_decode($m[1], true);
        $info = @getimagesizefromstring($png);
        if (strlen($png) < 500 || strlen($png) > 600_000 || ! $info || $info[2] !== IMAGETYPE_PNG || $info[0] > 3000 || $info[1] > 2000) {
            throw ValidationException::withMessages(['signature' => 'Semnătura nu a putut fi citită. Reîncercați.']);
        }

        return $png;
    }
}
