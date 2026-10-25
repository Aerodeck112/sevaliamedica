<?php

namespace App\Modules\Scheduling\Support;

/**
 * Statusurile programării și tranzițiile permise. Nicio tranziție nu se face automat pe baza orei.
 */
final class AppointmentStatus
{
    public const SOLICITARE = 'solicitare';

    public const CONFIRMATA = 'confirmata';

    public const SOSIT = 'sosit';

    public const IN_DESFASURARE = 'in_desfasurare';

    public const FINALIZATA = 'finalizata';

    public const ANULATA = 'anulata';

    public const NEPREZENTAT = 'neprezentat';

    public const LABELS = [
        self::SOLICITARE => 'Solicitare',
        self::CONFIRMATA => 'Confirmată',
        self::SOSIT => 'Sosit',
        self::IN_DESFASURARE => 'În consultație / procedură',
        self::FINALIZATA => 'Finalizată',
        self::ANULATA => 'Anulată',
        self::NEPREZENTAT => 'Neprezentat',
    ];

    /** Tonul vizual (clasa badge--*). Textul statusului se afișează întotdeauna. */
    public const TONES = [
        self::SOLICITARE => 'warn',
        self::CONFIRMATA => 'info',
        self::SOSIT => 'accent',
        self::IN_DESFASURARE => 'accent',
        self::FINALIZATA => 'ok',
        self::ANULATA => null,
        self::NEPREZENTAT => 'danger',
    ];

    public const TRANSITIONS = [
        self::SOLICITARE => [self::CONFIRMATA, self::ANULATA],
        self::CONFIRMATA => [self::SOSIT, self::ANULATA, self::NEPREZENTAT],
        self::SOSIT => [self::IN_DESFASURARE, self::CONFIRMATA, self::ANULATA],
        self::IN_DESFASURARE => [self::FINALIZATA],
        self::FINALIZATA => [],
        self::ANULATA => [],
        self::NEPREZENTAT => [],
    ];

    /** Tranziții care cer motiv. Cheie: "din>în" sau "*>în". */
    public const REASON_REQUIRED = ['*>'.self::ANULATA, '*>'.self::NEPREZENTAT, self::SOSIT.'>'.self::CONFIRMATA];

    /** Etichetele acțiunilor (butoane), din perspectiva utilizatorului. */
    public const ACTIONS = [
        self::CONFIRMATA => 'Confirmă',
        self::SOSIT => 'Marchează sosit',
        self::IN_DESFASURARE => 'Începe consultația / procedura',
        self::FINALIZATA => 'Finalizează',
        self::ANULATA => 'Anulează',
        self::NEPREZENTAT => 'Marchează neprezentat',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function tone(string $status): ?string
    {
        return self::TONES[$status] ?? null;
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function requiresReason(string $from, string $to): bool
    {
        return in_array('*>'.$to, self::REASON_REQUIRED, true) || in_array($from.'>'.$to, self::REASON_REQUIRED, true);
    }

    public static function actionLabel(string $from, string $to): string
    {
        if ($from === self::SOSIT && $to === self::CONFIRMATA) {
            return 'Anulează sosirea';
        }

        return self::ACTIONS[$to] ?? self::label($to);
    }

    /** Statusurile care țin resursele rezervate. */
    public static function blocking(): array
    {
        return config('scheduling.blocking_statuses', [self::CONFIRMATA, self::SOSIT, self::IN_DESFASURARE, self::FINALIZATA]);
    }
}
