<?php

namespace App\Modules\Notifications\Services;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;
use App\Modules\Notifications\Support\MessageType;

/**
 * Regulile de trimitere editabile din panou (Administrare → Notificări → Reguli), cu audit.
 * Valorile implicite sunt doar un punct de plecare; clinica le stabilește.
 */
class NotificationSettings
{
    public const CHANNEL_ORDERS = [
        'sms,email' => 'SMS, apoi e-mail',
        'whatsapp,sms,email' => 'WhatsApp, apoi SMS, apoi e-mail',
        'email,sms' => 'E-mail, apoi SMS',
        'email' => 'Doar e-mail',
        'sms' => 'Doar SMS',
        'whatsapp,email' => 'WhatsApp, apoi e-mail',
    ];

    public function __construct(private readonly Settings $settings) {}

    public static function definitions(): array
    {
        $defs = [];
        $defaultsOn = [MessageType::CONFIRMARE, MessageType::REMINDER, MessageType::ANULARE, MessageType::REPROGRAMARE, MessageType::SOLICITARE, MessageType::OFERTA, MessageType::FOLLOW_UP];
        foreach (MessageType::all() as $type => $meta) {
            $defs["notifications.type.$type"] = ['group' => 'Tipuri de mesaje active', 'label' => $meta['label'], 'hint' => $meta['help'],
                'type' => 'checkbox', 'default' => in_array($type, $defaultsOn, true), 'rules' => ['boolean']];
        }

        return $defs + [
            'notifications.channel_order' => ['group' => 'Canale', 'label' => 'Ordinea canalelor', 'type' => 'select', 'options' => self::CHANNEL_ORDERS,
                'hint' => 'Se folosesc doar canalele configurate și permise de pacient.', 'default' => 'sms,email', 'rules' => ['required', 'in:'.implode(',', array_keys(self::CHANNEL_ORDERS))]],
            'notifications.strategy' => ['group' => 'Canale', 'label' => 'Câte canale pentru același mesaj', 'type' => 'select',
                'options' => ['primul' => 'Primul canal disponibil', 'toate' => 'Toate canalele disponibile'], 'default' => 'primul', 'rules' => ['required', 'in:primul,toate']],
            'notifications.reminder_hours' => ['group' => 'Momente', 'label' => 'Reminderul se trimite cu', 'unit' => 'ore înainte', 'type' => 'number', 'default' => 24, 'rules' => ['required', 'integer', 'min:1', 'max:168']],
            'notifications.reminder_min_hours' => ['group' => 'Momente', 'label' => 'Nu se mai trimite reminder dacă au rămas sub', 'unit' => 'ore', 'type' => 'number', 'default' => 2, 'rules' => ['required', 'integer', 'min:0', 'max:48']],
            'notifications.quiet_start' => ['group' => 'Momente', 'label' => 'Ore de liniște: de la', 'type' => 'time', 'default' => '21:00', 'rules' => ['required', 'date_format:H:i']],
            'notifications.quiet_end' => ['group' => 'Momente', 'label' => 'Ore de liniște: până la', 'type' => 'time', 'default' => '08:00', 'rules' => ['required', 'date_format:H:i'],
                'hint' => 'Mesajele programate în acest interval pleacă la sfârșitul lui. E-mailurile respectă și ele intervalul.'],
            'notifications.feedback_delay_hours' => ['group' => 'Momente', 'label' => 'Solicitarea de feedback după finalizare', 'unit' => 'ore', 'type' => 'number', 'default' => 3, 'rules' => ['required', 'integer', 'min:0', 'max:168']],
            'notifications.feedback_ttl_days' => ['group' => 'Momente', 'label' => 'Linkul de feedback e valabil', 'unit' => 'zile', 'type' => 'number', 'default' => 14, 'rules' => ['required', 'integer', 'min:1', 'max:90']],
            'notifications.max_attempts' => ['group' => 'Trimitere', 'label' => 'Număr maxim de încercări', 'type' => 'number', 'default' => 5, 'rules' => ['required', 'integer', 'min:1', 'max:10']],
            'notifications.batch_size' => ['group' => 'Trimitere', 'label' => 'Mesaje trimise la o rulare a cronului', 'type' => 'number', 'default' => 50, 'rules' => ['required', 'integer', 'min:1', 'max:500']],
        ];
    }

    public function get(string $key): mixed
    {
        $def = self::definitions()[$key] ?? null;
        $value = $this->settings->get($key, $def['default'] ?? null);

        return match ($def['type'] ?? null) {
            'number' => (int) $value,
            'checkbox' => (bool) $value,
            default => $value,
        };
    }

    public function typeEnabled(string $type): bool
    {
        return (bool) $this->get("notifications.type.$type");
    }

    /** @return list<string> */
    public function channelOrder(): array
    {
        return explode(',', (string) $this->get('notifications.channel_order'));
    }

    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::definitions()) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function update(array $values): array
    {
        $changed = [];
        foreach (self::definitions() as $key => $def) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $new = match ($def['type']) {
                'number' => (int) $values[$key],
                'checkbox' => (bool) $values[$key],
                default => (string) $values[$key],
            };
            $old = $this->get($key);
            if ($old !== $new) {
                $this->settings->set($key, $new);
                $changed[$key] = ['inainte' => $old, 'dupa' => $new];
            }
        }
        if ($changed) {
            Audit::log('notifications.settings_updated', context: ['modificari' => $changed]);
        }

        return $changed;
    }
}
