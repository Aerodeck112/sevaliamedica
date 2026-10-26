<?php

namespace App\Modules\Notifications\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Notifications\Channels\OutgoingMessage;
use App\Modules\Notifications\Channels\SendResult;
use App\Modules\Notifications\Models\FeedbackRequest;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\MessageType;
use App\Modules\Scheduling\Models\WaitlistOffer;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trimite livrările scadente, în loturi, din cron. Fiecare livrare este „revendicată” atomic
 * (UPDATE condiționat) înainte de trimitere, deci două rulări suprapuse nu o pot trimite de două ori.
 *
 * Dacă procesul se oprește după trimitere dar înainte de salvarea rezultatului, livrarea rămâne „în trimitere”
 * și, după expirarea blocării, devine „rezultat necunoscut” — NU se retrimite automat (ar putea produce un duplicat);
 * recepția decide reîncercarea manuală din jurnal.
 */
class DeliveryDispatcher
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly NotificationSettings $settings,
        private readonly NotificationPlanner $planner,
    ) {}

    /** @return array{sent: int, simulated: int, failed: int, cancelled: int, unknown: int} */
    public function run(?int $limit = null): array
    {
        $stats = ['sent' => 0, 'simulated' => 0, 'failed' => 0, 'cancelled' => 0, 'unknown' => 0];
        $now = CarbonImmutable::now();

        $stats['unknown'] = NotificationDelivery::where('status', NotificationDelivery::SENDING)
            ->where('locked_until', '<', $now)
            ->update(['status' => NotificationDelivery::UNKNOWN, 'last_error' => 'Procesul de trimitere s-a întrerupt; nu se știe dacă mesajul a plecat. Verificați înainte de a reîncerca.', 'updated_at' => $now]);

        // În orele de liniște nu trimitem nimic; livrările scadente așteaptă sfârșitul intervalului.
        if (! $this->planner->outsideQuietHours($now)->equalTo($now)) {
            return $stats;
        }

        $ids = NotificationDelivery::whereIn('status', [NotificationDelivery::PENDING, NotificationDelivery::FAILED])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('next_attempt_at')->orderBy('id')
            ->limit($limit ?? (int) $this->settings->get('notifications.batch_size'))
            ->pluck('id');

        foreach ($ids as $id) {
            $outcome = $this->deliver((int) $id);
            if ($outcome !== null) {
                $stats[$outcome]++;
            }
        }

        return $stats;
    }

    /** Trimite o singură livrare dacă reușește să o revendice. @return 'sent'|'simulated'|'failed'|'cancelled'|null */
    public function deliver(int $id): ?string
    {
        $now = CarbonImmutable::now();
        $claimed = NotificationDelivery::whereKey($id)
            ->whereIn('status', [NotificationDelivery::PENDING, NotificationDelivery::FAILED])
            ->update([
                'status' => NotificationDelivery::SENDING,
                'locked_until' => $now->addMinutes((int) config('notifications.sending_lock_minutes', 5)),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            return null; // preluată de alt proces sau deja finalizată
        }

        $delivery = NotificationDelivery::with(['appointment'])->findOrFail($id);

        if ($reason = $this->obsoleteReason($delivery)) {
            $delivery->update(['status' => NotificationDelivery::CANCELLED, 'skip_reason' => $reason, 'locked_until' => null]);

            return 'cancelled';
        }
        if (! $this->channels->isUsable($delivery->channel)) {
            $delivery->update(['status' => NotificationDelivery::CANCELLED, 'skip_reason' => 'Canalul a fost dezactivat sau nu este configurat.', 'locked_until' => null]);

            return 'cancelled';
        }

        $payload = $delivery->payload ?? [];
        try {
            $result = $this->channels->driver($delivery->channel)->send(new OutgoingMessage(
                channel: $delivery->channel,
                recipient: (string) $delivery->recipient,
                body: (string) ($payload['body'] ?? ''),
                subject: $payload['subject'] ?? null,
                providerTemplate: $payload['provider_template'] ?? null,
                providerParams: $payload['provider_params'] ?? [],
                reference: 'livrare-'.$delivery->id,
            ));
        } catch (Throwable $e) {
            $result = SendResult::temporary('Eroare internă la trimitere: '.class_basename($e));
        }

        return $this->record($delivery, $result);
    }

    private function record(NotificationDelivery $delivery, SendResult $result): string
    {
        if ($result->ok) {
            $delivery->update([
                'status' => $result->simulated ? NotificationDelivery::SIMULATED : NotificationDelivery::SENT,
                'sent_at' => now(), 'provider_message_id' => $result->providerId, 'last_error' => null, 'locked_until' => null,
                'skip_reason' => $result->simulated ? 'Trimiterea reală este oprită în acest mediu sau canalul folosește jurnalul de test.' : null,
            ]);

            return $result->simulated ? 'simulated' : 'sent';
        }

        $max = (int) $this->settings->get('notifications.max_attempts');
        $final = ! $result->retryable || $delivery->attempts >= $max;
        $backoff = config('notifications.backoff_minutes', [1, 5, 30, 120, 360]);
        $delay = (int) ($backoff[min($delivery->attempts - 1, count($backoff) - 1)] ?? 60);
        $delivery->update([
            'status' => $final ? NotificationDelivery::FAILED_FINAL : NotificationDelivery::FAILED,
            'last_error' => $result->error,
            'next_attempt_at' => $final ? null : now()->addMinutes($delay),
            'locked_until' => null,
        ]);

        return 'failed';
    }

    /** Un mesaj care nu mai corespunde realității nu se trimite (ex. confirmare pentru o programare deja anulată). */
    public function obsoleteReason(NotificationDelivery $d): ?string
    {
        $a = $d->appointment;
        $isFutureConfirmed = $a && $a->status === AppointmentStatus::CONFIRMATA && $a->starts_at && $a->starts_at->isFuture();

        return match ($d->type) {
            MessageType::CONFIRMARE => $isFutureConfirmed ? null : 'Programarea nu mai este confirmată sau a trecut.',
            MessageType::REMINDER => ! $isFutureConfirmed ? 'Programarea nu mai este confirmată sau a trecut.'
                : ($d->variant !== 'start:'.$a->starts_at->format('Y-m-d H:i') ? 'Ora programării s-a schimbat.' : null),
            MessageType::REPROGRAMARE => ! $isFutureConfirmed ? 'Programarea nu mai este confirmată sau a trecut.'
                : ($d->variant !== 'v'.$a->version ? 'Programarea a fost modificată din nou; pleacă doar mesajul cel mai recent.' : null),
            MessageType::ANULARE => $a && $a->status === AppointmentStatus::ANULATA ? null : 'Programarea nu mai este anulată.',
            MessageType::OFERTA => $this->offerObsolete($d),
            MessageType::FEEDBACK => FeedbackRequest::where('appointment_id', $d->appointment_id)->get()->first()?->isOpen() ? null : 'Feedbackul a fost deja dat sau linkul a expirat.',
            default => null,
        };
    }

    private function offerObsolete(NotificationDelivery $d): ?string
    {
        $offer = $d->subject_type === 'waitlist_offer' ? WaitlistOffer::find($d->subject_id) : null;
        if (! $offer || $offer->outcome !== 'in_asteptare') {
            return 'Oferta nu mai este în așteptare.';
        }

        return CarbonImmutable::parse($offer->slot_start)->isPast() ? 'Intervalul oferit a trecut.' : null;
    }

    /** Reîncercare manuală din jurnal (inclusiv pentru „rezultat necunoscut”, după verificare de către recepție). */
    public function retry(NotificationDelivery $delivery, User $user): void
    {
        if (! $delivery->canRetry()) {
            return;
        }
        $delivery->update(['status' => NotificationDelivery::PENDING, 'next_attempt_at' => now(), 'locked_until' => null]);
        Audit::log('notifications.retry', $delivery, ['tip' => $delivery->type, 'canal' => $delivery->channel], $delivery->patient_id, $user->id);
    }
}
