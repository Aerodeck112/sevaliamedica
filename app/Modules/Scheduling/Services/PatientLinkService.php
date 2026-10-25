<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\PatientAccessToken;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Linkuri pentru pacient (/p/{token}) — anulare și cerere de reprogramare. Contract pentru Notifications:
 *
 *     $url = app(PatientLinkService::class)->issue($appointment)['url'];
 *
 * Tokenul are 32 de octeți aleatori, se păstrează doar ca SHA-256, expiră, iar anularea îl consumă.
 * Pagina afișează doar data, ora, locația și denumirea generică a serviciului — niciun diagnostic.
 */
class PatientLinkService
{
    public function __construct(private readonly BookingService $booking, private readonly SchedulingSettings $settings) {}

    /** @return array{token: string, url: string, expires_at: CarbonImmutable} */
    public function issue(Appointment $appointment, ?User $user = null, string $purpose = 'gestionare'): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = CarbonImmutable::now()->addHours((int) $this->settings->get('scheduling.patient_link_ttl_hours'));
        if ($appointment->starts_at && $appointment->starts_at < $expires) {
            $expires = $appointment->starts_at; // linkul nu e util după ora programării
        }
        PatientAccessToken::create([
            'appointment_id' => $appointment->id, 'token_hash' => hash('sha256', $token),
            'purpose' => $purpose, 'expires_at' => $expires, 'created_at' => now(),
        ]);
        $this->booking->event($appointment, $user, 'link_emis', null, null, null, ['expira' => $expires->format('Y-m-d H:i')]);

        return ['token' => $token, 'url' => route('patient-link.show', $token), 'expires_at' => $expires];
    }

    public function find(string $token): ?PatientAccessToken
    {
        if (strlen($token) < 40 || strlen($token) > 64) {
            return null;
        }

        return PatientAccessToken::with('appointment.location', 'appointment.service')->where('token_hash', hash('sha256', $token))->first();
    }

    public function canCancel(PatientAccessToken $t): bool
    {
        $a = $t->appointment;

        return $t->isUsable() && $a->status === AppointmentStatus::CONFIRMATA && $a->starts_at
            && $a->starts_at->subHours((int) $this->settings->get('scheduling.patient_cancel_min_hours'))->isFuture();
    }

    public function cancel(PatientAccessToken $t): Appointment
    {
        return DB::transaction(function () use ($t) {
            $locked = PatientAccessToken::whereKey($t->id)->lockForUpdate()->first();
            if (! $locked || ! $this->canCancel($locked)) {
                throw ValidationException::withMessages(['token' => 'Linkul nu mai poate fi folosit pentru anulare. Vă rugăm să ne contactați telefonic.']);
            }
            $appointment = $this->booking->changeStatus($locked->appointment, AppointmentStatus::ANULATA, null, 'Anulată de pacient prin link', true);
            $locked->update(['used_at' => now()]);

            return $appointment;
        });
    }

    public function requestReschedule(PatientAccessToken $t, string $message): void
    {
        if (! $t->isUsable() || ! in_array($t->appointment->status, [AppointmentStatus::CONFIRMATA], true)) {
            throw ValidationException::withMessages(['token' => 'Linkul nu mai este valabil. Vă rugăm să ne contactați telefonic.']);
        }
        $a = $t->appointment;
        $a->forceFill(['reschedule_requested_at' => now()])->save();
        $this->booking->event($a, null, 'reprogramare_ceruta', null, null, mb_substr(trim($message), 0, 500) ?: null);
        Audit::log('scheduling.reschedule_requested_by_patient', $a, [], $a->patient_id);
    }
}
