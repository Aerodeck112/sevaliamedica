<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Channels\EmailChannel;
use App\Modules\Notifications\Channels\GenericHttpSmsChannel;
use App\Modules\Notifications\Channels\LogChannel;
use App\Modules\Notifications\Channels\NotificationChannel;
use App\Modules\Notifications\Channels\WhatsAppCloudChannel;
use App\Modules\Notifications\Models\NotificationChannelConfig;

/**
 * Alege driverul pentru un canal. În afara producției, trimiterea reală este blocată implicit:
 * orice driver real este înlocuit cu cel de jurnal, iar livrarea apare „simulat”.
 */
class ChannelManager
{
    /** @var array<string, NotificationChannelConfig> */
    private array $configs = [];

    public function config(string $channel): NotificationChannelConfig
    {
        return $this->configs[$channel] ??= NotificationChannelConfig::for($channel);
    }

    public function forget(): void
    {
        $this->configs = [];
    }

    public function isUsable(string $channel): bool
    {
        return $this->config($channel)->isUsable();
    }

    public function realSendingAllowed(): bool
    {
        return config('sevalia.stage') === 'production' || (bool) config('notifications.real_sending_outside_production');
    }

    public function driver(string $channel): NotificationChannel
    {
        $config = $this->config($channel);
        $real = $this->realDriver($config);
        if ($real instanceof LogChannel || ! $this->realSendingAllowed()) {
            return new LogChannel;
        }

        return $real;
    }

    /** Driverul configurat, fără blocarea din afara producției (pentru verificarea configurării). */
    public function realDriver(NotificationChannelConfig $config): NotificationChannel
    {
        $settings = $config->config ?? [];

        return match ($config->driver) {
            'smtp' => new EmailChannel($settings),
            'http_generic' => new GenericHttpSmsChannel($settings),
            'whatsapp_cloud' => new WhatsAppCloudChannel($settings),
            default => new LogChannel,
        };
    }
}
