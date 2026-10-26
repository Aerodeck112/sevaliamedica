<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Services\FeedbackService;
use App\Modules\Notifications\Services\NotificationPlanner;
use App\Modules\Notifications\Services\NotificationSettings;
use App\Modules\Notifications\Support\MessageType;
use App\Modules\Scheduling\Events\AppointmentBooked;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Events\AppointmentMoved;
use App\Modules\Scheduling\Events\AppointmentRequestReceived;
use App\Modules\Scheduling\Events\AppointmentStatusChanged;
use App\Modules\Scheduling\Events\WaitlistSlotOffered;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leagă evenimentele programărilor de livrări. Doar planifică (inserează livrări); trimiterea se face din cron.
 * O eroare aici nu oprește niciodată operația de programare care a emis evenimentul.
 */
class AppointmentNotificationSubscriber
{
    public function __construct(
        private readonly NotificationPlanner $planner,
        private readonly NotificationSettings $settings,
        private readonly FeedbackService $feedback,
    ) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            AppointmentBooked::class => 'onBooked',
            AppointmentStatusChanged::class => 'onStatusChanged',
            AppointmentCancelled::class => 'onCancelled',
            AppointmentMoved::class => 'onMoved',
            AppointmentRequestReceived::class => 'onRequest',
            WaitlistSlotOffered::class => 'onOffer',
        ];
    }

    /**
     * Programare creată direct confirmată (recepție). Ședințele generate dintr-o serie nu primesc câte o confirmare
     * fiecare (ar fi zeci de mesaje); pentru ele pleacă doar reminderele.
     */
    public function onBooked(AppointmentBooked $e): void
    {
        $a = $e->appointment;
        if ($a->status !== AppointmentStatus::CONFIRMATA || $a->series_id || ! $a->patient_id) {
            return;
        }
        $this->safely(function () use ($a) {
            $fresh = $a->fresh();
            $this->planner->forAppointment(MessageType::CONFIRMARE, $fresh, 'v'.$fresh->version, withLink: true);
        });
    }

    public function onStatusChanged(AppointmentStatusChanged $e): void
    {
        $this->safely(function () use ($e) {
            $a = $e->appointment->fresh();
            if ($e->to === AppointmentStatus::CONFIRMATA) {
                $this->planner->forAppointment(MessageType::CONFIRMARE, $a, 'v'.$a->version, withLink: true);
            }
            if ($e->to === AppointmentStatus::FINALIZATA && $a->patient_id && $this->settings->typeEnabled(MessageType::FEEDBACK)) {
                $link = $this->feedback->issue($a);
                if ($link) {
                    $delay = (int) $this->settings->get('notifications.feedback_delay_hours');
                    $this->planner->forAppointment(MessageType::FEEDBACK, $a, 'feedback', CarbonImmutable::now()->addHours($delay), ['link_feedback' => $link]);
                }
            }
        });
    }

    public function onCancelled(AppointmentCancelled $e): void
    {
        // Pacientul care a anulat singur prin link vede deja confirmarea pe pagină; nu îi mai trimitem mesaj.
        if ($e->byPatient) {
            return;
        }
        $this->safely(function () use ($e) {
            $a = $e->appointment->fresh();
            if ($a->starts_at && $a->starts_at->isFuture()) {
                $this->planner->forAppointment(MessageType::ANULARE, $a, 'v'.$a->version);
            }
        });
    }

    public function onMoved(AppointmentMoved $e): void
    {
        $this->safely(function () use ($e) {
            $a = $e->appointment->fresh();
            if ($a->status === AppointmentStatus::CONFIRMATA && $a->starts_at?->isFuture()) {
                $this->planner->forAppointment(MessageType::REPROGRAMARE, $a, 'v'.$a->version, withLink: true);
            }
        });
    }

    public function onRequest(AppointmentRequestReceived $e): void
    {
        // O solicitare confirmată automat primește mesajul de confirmare (din schimbarea de status), nu pe acesta.
        if ($e->autoConfirmed) {
            return;
        }
        $this->safely(fn () => $this->planner->forAppointment(MessageType::SOLICITARE, $e->appointment->fresh(), 'request'));
    }

    public function onOffer(WaitlistSlotOffered $e): void
    {
        $this->safely(fn () => $this->planner->forWaitlistOffer($e->offer));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::error('Planificarea notificării a eșuat', ['exceptie' => class_basename($e), 'mesaj' => mb_substr($e->getMessage(), 0, 200)]);
            if (app()->runningUnitTests()) {
                throw $e;
            }
        }
    }

    /** Folosit de scanarea reminderelor. */
    public static function reminderVariant(Appointment $a): string
    {
        return 'start:'.$a->starts_at->format('Y-m-d H:i');
    }
}
