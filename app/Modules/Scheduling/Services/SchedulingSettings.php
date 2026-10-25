<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\Audit;
use App\Core\Settings\Settings;

/**
 * Regulile operaționale de programare și acces, editabile din panou (Administrare → Reguli programări)
 * de cei cu permisiunea scheduling.settings. Valorile implicite din config/scheduling.php și config/patients.php
 * sunt doar puncte de plecare; clinica le stabilește.
 */
class SchedulingSettings
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array<string, array{label: string, type: string, default: mixed, rules: array, hint?: string, group: string, unit?: string}>
     */
    public static function definitions(): array
    {
        return [
            'scheduling.slot_minutes' => ['group' => 'Calendar', 'label' => 'Pasul grilei calendarului', 'unit' => 'minute', 'type' => 'select',
                'options' => [5 => '5', 10 => '10', 15 => '15', 20 => '20', 30 => '30'], 'default' => config('scheduling.slot_minutes', 15), 'rules' => ['required', 'integer', 'in:5,10,15,20,30']],
            'scheduling.day_start' => ['group' => 'Calendar', 'label' => 'Ora de început afișată', 'type' => 'time', 'default' => config('scheduling.day_start', '07:00'), 'rules' => ['required', 'date_format:H:i']],
            'scheduling.day_end' => ['group' => 'Calendar', 'label' => 'Ora de sfârșit afișată', 'type' => 'time', 'default' => config('scheduling.day_end', '22:00'), 'rules' => ['required', 'date_format:H:i', 'after:scheduling_day_start']],
            'scheduling.enforce_working_hours' => ['group' => 'Reguli de rezervare', 'label' => 'Refuză programările în afara programului de lucru', 'type' => 'checkbox',
                'hint' => 'Cine are permisiunea „rezervare în afara programului” poate forța, cu motiv. Persoanele fără niciun program configurat primesc doar avertizare.', 'default' => config('scheduling.enforce_working_hours', true), 'rules' => ['boolean']],
            'scheduling.suggestions' => ['group' => 'Reguli de rezervare', 'label' => 'Intervale libere sugerate la mutare/conflict', 'type' => 'number', 'default' => config('scheduling.suggestions', 8), 'rules' => ['required', 'integer', 'min:1', 'max:30']],
            'scheduling.online.enabled' => ['group' => 'Solicitări de pe site', 'label' => 'Acceptă solicitări de programare de pe site', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
            'scheduling.online.auto_confirm' => ['group' => 'Solicitări de pe site', 'label' => 'Permite confirmarea automată (doar pentru serviciile marcate și intervale libere)', 'type' => 'checkbox',
                'hint' => 'Dezactivat: toate solicitările așteaptă recepția, iar pacientul primește doar confirmarea înregistrării.', 'default' => false, 'rules' => ['boolean']],
            'scheduling.online.min_advance_hours' => ['group' => 'Solicitări de pe site', 'label' => 'Avans minim pentru un interval ales online', 'unit' => 'ore', 'type' => 'number', 'default' => 24, 'rules' => ['required', 'integer', 'min:0', 'max:720']],
            'scheduling.online.max_advance_days' => ['group' => 'Solicitări de pe site', 'label' => 'Cât de departe în viitor se poate alege un interval online', 'unit' => 'zile', 'type' => 'number', 'default' => 60, 'rules' => ['required', 'integer', 'min:1', 'max:365']],
            'scheduling.online.hold_minutes' => ['group' => 'Solicitări de pe site', 'label' => 'Ținerea intervalului cerut până la răspunsul recepției', 'unit' => 'minute (0 = nu se ține)', 'type' => 'number', 'default' => 0, 'rules' => ['required', 'integer', 'min:0', 'max:2880']],
            'scheduling.patient_link_ttl_hours' => ['group' => 'Linkuri pentru pacient', 'label' => 'Valabilitatea linkului de anulare / reprogramare', 'unit' => 'ore', 'type' => 'number', 'default' => config('scheduling.patient_link_ttl_hours', 336), 'rules' => ['required', 'integer', 'min:1', 'max:2160']],
            'scheduling.patient_cancel_min_hours' => ['group' => 'Linkuri pentru pacient', 'label' => 'Anularea prin link permisă cu cel puțin', 'unit' => 'ore înainte', 'type' => 'number', 'default' => config('scheduling.patient_cancel_min_hours', 2), 'rules' => ['required', 'integer', 'min:0', 'max:168']],
            'patients.care_window_past_days' => ['group' => 'Acces la date medicale', 'label' => 'Relația de îngrijire din programări: zile în urmă', 'unit' => 'zile', 'type' => 'number', 'default' => config('patients.care_window_past_days', 365), 'rules' => ['required', 'integer', 'min:0', 'max:3650']],
            'patients.care_window_future_days' => ['group' => 'Acces la date medicale', 'label' => 'Relația de îngrijire din programări: zile înainte', 'unit' => 'zile', 'type' => 'number', 'default' => config('patients.care_window_future_days', 60), 'rules' => ['required', 'integer', 'min:0', 'max:365']],
            'patients.break_glass_minutes' => ['group' => 'Acces la date medicale', 'label' => 'Durata accesului de urgență', 'unit' => 'minute', 'type' => 'number', 'default' => config('patients.break_glass_minutes', 60), 'rules' => ['required', 'integer', 'min:5', 'max:720']],
        ];
    }

    public function get(string $key): mixed
    {
        $def = self::definitions()[$key] ?? null;
        $value = $this->settings->get($key, $def['default'] ?? null);

        return match ($def['type'] ?? null) {
            'number', 'select' => (int) $value,
            'checkbox' => (bool) $value,
            default => $value,
        };
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::definitions()) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** Salvează valorile validate și auditează diferențele. */
    public function update(array $values): array
    {
        $changed = [];
        foreach (self::definitions() as $key => $def) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $new = match ($def['type']) {
                'number', 'select' => (int) $values[$key],
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
            Audit::log('scheduling.settings_updated', context: ['modificari' => $changed]);
        }

        return $changed;
    }
}
