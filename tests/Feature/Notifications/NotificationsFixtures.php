<?php

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Models\NotificationChannelConfig;
use App\Modules\Notifications\Services\ChannelManager;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Database\Seeders\NotificationTemplatesSeeder;
use Tests\Feature\Scheduling\SchedulingFixtures;

trait NotificationsFixtures
{
    use SchedulingFixtures;

    protected function setUpNotifications(): void
    {
        $this->setUpClinic();
        $this->location->update(['phone' => '0300 000 000']);
        $this->seed(NotificationTemplatesSeeder::class);
    }

    /** Canal SMS printr-un API HTTP de test (răspunsurile se simulează cu Http::fake). */
    protected function configureSms(bool $enabled = true): void
    {
        NotificationChannelConfig::updateOrCreate(['channel' => 'sms'], [
            'driver' => 'http_generic', 'enabled' => $enabled,
            'config' => ['url' => 'https://sms.exemplu.invalid/send', 'method' => 'POST', 'body_format' => 'json',
                'auth_header' => 'Authorization', 'auth_value' => 'Bearer test-secret', 'field_to' => 'to', 'field_message' => 'text', 'message_id_path' => 'data.id'],
        ]);
        app(ChannelManager::class)->forget();
    }

    protected function configureEmailLog(): void
    {
        NotificationChannelConfig::updateOrCreate(['channel' => 'email'], ['driver' => 'log', 'enabled' => true, 'config' => []]);
        app(ChannelManager::class)->forget();
    }

    protected function book(int $patientId, CarbonImmutable $start, ?int $seriesId = null): Appointment
    {
        return app(BookingService::class)->book(new BookingRequest(
            patientId: $patientId, serviceId: $this->consult->id, staffMemberId: $this->medic->id,
            locationId: $this->location->id, startsAt: $start, seriesId: $seriesId,
        ), null);
    }

    protected function production(): void
    {
        config(['sevalia.stage' => 'production']);
    }
}
