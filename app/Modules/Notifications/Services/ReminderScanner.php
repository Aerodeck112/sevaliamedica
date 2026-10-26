<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Listeners\AppointmentNotificationSubscriber;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\MessageType;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Carbon\CarbonImmutable;

/**
 * Planifică reminderele pentru programările confirmate care intră în fereastra configurată.
 * Poate rula oricât de des: cheia de idempotență (programare + ora programării) împiedică dublarea.
 * Dacă pacientul a primit confirmarea deja în fereastra reminderului (programare făcută cu puțin timp înainte),
 * reminderul nu se mai trimite.
 */
class ReminderScanner
{
    public function __construct(private readonly NotificationPlanner $planner, private readonly NotificationSettings $settings) {}

    public function run(): int
    {
        if (! $this->settings->typeEnabled(MessageType::REMINDER)) {
            return 0;
        }
        $now = CarbonImmutable::now();
        $hours = (int) $this->settings->get('notifications.reminder_hours');
        $minHours = (int) $this->settings->get('notifications.reminder_min_hours');

        $planned = 0;
        Appointment::where('status', AppointmentStatus::CONFIRMATA)
            ->whereNotNull('patient_id')
            ->whereBetween('starts_at', [$now->addHours($minHours), $now->addHours($hours)])
            ->orderBy('starts_at')
            ->chunkById(200, function ($appointments) use (&$planned, $hours) {
                foreach ($appointments as $a) {
                    $recentConfirmation = NotificationDelivery::where('appointment_id', $a->id)
                        ->where('type', MessageType::CONFIRMARE)
                        ->whereIn('status', [NotificationDelivery::PENDING, NotificationDelivery::SENDING, NotificationDelivery::SENT, NotificationDelivery::SIMULATED])
                        ->where('created_at', '>=', $a->starts_at->subHours($hours))
                        ->exists();
                    if ($recentConfirmation) {
                        continue;
                    }
                    $planned += count($this->planner->forAppointment(MessageType::REMINDER, $a, AppointmentNotificationSubscriber::reminderVariant($a), withLink: true));
                }
            });

        return $planned;
    }
}
