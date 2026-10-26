<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configurarea unui canal (email | sms | whatsapp). `config` este criptat și conține și secretele
 * (tokenuri API), care nu se afișează niciodată înapoi în panou.
 */
class NotificationChannelConfig extends Model
{
    public const DRIVERS = [
        'email' => ['neconfigurat' => 'Neconfigurat', 'smtp' => 'E-mail prin serverul SMTP din configurare', 'log' => 'Jurnal local (test, nu trimite)'],
        'sms' => ['neconfigurat' => 'Neconfigurat', 'http_generic' => 'API HTTP al furnizorului SMS (configurabil)', 'log' => 'Jurnal local (test, nu trimite)'],
        'whatsapp' => ['neconfigurat' => 'Neconfigurat', 'whatsapp_cloud' => 'WhatsApp Business Cloud API (Meta)', 'log' => 'Jurnal local (test, nu trimite)'],
    ];

    protected $table = 'notification_channels';

    protected $primaryKey = 'channel';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'config' => 'encrypted:array'];
    }

    public static function for(string $channel): self
    {
        return self::firstOrNew(['channel' => $channel], ['driver' => 'neconfigurat', 'enabled' => false, 'config' => []]);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    public function isUsable(): bool
    {
        return $this->exists && $this->enabled && $this->driver !== 'neconfigurat';
    }
}
