<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\Audit;
use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\IntakeResult;
use App\Core\Contracts\PublicAppointmentRequest;
use App\Modules\Clinic\Models\Service;
use App\Modules\Scheduling\Events\AppointmentRequestReceived;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Preia solicitările de pe site în CRM. Nu creează fișe de pacient: recepția identifică pacientul.
 *
 * Confirmare automată DOAR dacă toate sunt adevărate: setarea globală o permite, serviciul are
 * online_auto_confirm, cererea are un interval concret, persoană și locație, intervalul respectă avansul
 * minim/maxim și este liber. Altfel mesajul confirmă doar înregistrarea solicitării.
 */
class AppointmentIntakeService implements AppointmentIntake
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly SchedulingSettings $settings,
    ) {}

    public function submit(PublicAppointmentRequest $r): IntakeResult
    {
        if (! $this->settings->get('scheduling.online.enabled')) {
            return new IntakeResult(false, false, 'Momentan nu preluăm solicitări online. Vă rugăm să ne contactați telefonic.');
        }

        $reference = $this->reference();
        $contact = array_filter([
            'name' => Str::limit(trim($r->name), 160, ''),
            'phone' => $r->phone, 'email' => $r->email, 'preferred_contact' => $r->preferredContact,
            'message' => $r->message ? Str::limit(trim($r->message), 2000, '') : null,
            'birth_date' => $r->birthDate,
            'consent_at' => $r->consentAt->toIso8601String(),
            'source_url' => Str::limit($r->sourceUrl, 255, ''),
            'ip_hash' => $r->ipHash,
        ], fn ($v) => $v !== null && $v !== '');

        $appointment = DB::transaction(fn () => Appointment::create([
            'status' => AppointmentStatus::SOLICITARE,
            'source' => 'site',
            'service_id' => $r->serviceId,
            'staff_member_id' => $r->staffMemberId,
            'location_id' => $r->locationId,
            'request_specialty_id' => $r->specialtyId,
            'request_preferred_start' => $r->preferredStart,
            'request_contact' => $contact,
            'request_reference' => $reference,
        ]));
        $this->booking->event($appointment, null, 'creata', null, AppointmentStatus::SOLICITARE, null, ['sursa' => 'formular site']);
        Audit::log('scheduling.request_received', $appointment, ['referinta' => $reference]);

        $confirmed = false;
        $request = $this->bookingRequest($r);
        if ($request && $this->autoConfirmAllowed($r)) {
            try {
                // Pacientul nu e încă identificat: recepția leagă fișa ulterior, intervalul rămâne rezervat.
                $this->booking->confirmRequest($appointment, $request, null, requirePatient: false);
                $confirmed = true;
            } catch (BookingConflictException) {
                $confirmed = false;
            }
        } elseif ($request && ($hold = (int) $this->settings->get('scheduling.online.hold_minutes')) > 0 && $this->withinAdvanceWindow($r->preferredStart)) {
            $this->booking->holdForRequest($appointment, $request->with(['status' => AppointmentStatus::SOLICITARE]), CarbonImmutable::now()->addMinutes($hold));
        }

        $final = $appointment->fresh('location');
        event(new AppointmentRequestReceived($final, $confirmed));

        if ($confirmed) {
            return new IntakeResult(true, true,
                'Programarea a fost confirmată pentru '.$final->starts_at->locale('ro')->isoFormat('dddd, D MMMM YYYY, [ora] HH:mm').($final->location ? ', '.$final->location->name : '').'. Vă vom contacta dacă apar modificări.',
                $reference);
        }

        return new IntakeResult(true, false,
            'Solicitarea a fost înregistrată (cod '.$reference.'). Un coleg de la recepție vă va contacta pentru stabilirea programării. Solicitarea nu reprezintă încă o programare confirmată.',
            $reference);
    }

    private function bookingRequest(PublicAppointmentRequest $r): ?BookingRequest
    {
        if (! $r->preferredStart || ! $r->serviceId || ! $r->locationId) {
            return null;
        }

        return new BookingRequest(0, $r->serviceId, $r->staffMemberId, $r->locationId, $r->preferredStart, source: 'site');
    }

    private function autoConfirmAllowed(PublicAppointmentRequest $r): bool
    {
        if (! $this->settings->get('scheduling.online.auto_confirm') || ! $r->staffMemberId) {
            return false;
        }
        $service = Service::find($r->serviceId);

        return $service && $service->is_active && $service->online_booking && $service->online_auto_confirm
            && $this->withinAdvanceWindow($r->preferredStart);
    }

    private function withinAdvanceWindow(?CarbonImmutable $start): bool
    {
        if (! $start) {
            return false;
        }
        $now = CarbonImmutable::now();

        return $start >= $now->addHours((int) $this->settings->get('scheduling.online.min_advance_hours'))
            && $start <= $now->addDays((int) $this->settings->get('scheduling.online.max_advance_days'));
    }

    private function reference(): string
    {
        do {
            $ref = 'S'.strtoupper(substr(str_replace(['0', '1', 'O', 'I'], '', base_convert(bin2hex(random_bytes(8)), 16, 36)), 0, 7));
        } while (strlen($ref) < 8 || Appointment::where('request_reference', $ref)->exists());

        return $ref;
    }
}
