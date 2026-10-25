<?php

namespace App\Modules\Identity;

/**
 * Rolurile standard. Administrarea tehnică este separată de accesul medical: administratorul tehnic nu
 * primește implicit nicio permisiune asupra datelor medicale.
 */
final class Roles
{
    public const ADMIN_TEHNIC = 'administrator_tehnic';

    public const MANAGER = 'manager';

    public const RECEPTIE = 'receptie';

    public const MEDIC = 'medic';

    public const TERAPEUT = 'terapeut';

    public const ASISTENT = 'asistent';

    public const RESPONSABIL_CAS = 'responsabil_cas';

    public const FINANCIAR = 'financiar';

    public const EDITOR_SITE = 'editor_site';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::ADMIN_TEHNIC => 'Administrator tehnic',
            self::MANAGER => 'Manager clinică',
            self::RECEPTIE => 'Recepție',
            self::MEDIC => 'Medic',
            self::TERAPEUT => 'Terapeut / fizioterapeut',
            self::ASISTENT => 'Asistent medical',
            self::RESPONSABIL_CAS => 'Responsabil CAS',
            self::FINANCIAR => 'Financiar / casierie',
            self::EDITOR_SITE => 'Editor site',
        ];
    }

    public static function label(string $role): string
    {
        return self::all()[$role] ?? $role;
    }

    /** Rolurile care au, prin natura lor, relație de îngrijire cu pacienții. */
    public static function clinical(): array
    {
        return [self::MEDIC, self::TERAPEUT, self::ASISTENT];
    }
}
