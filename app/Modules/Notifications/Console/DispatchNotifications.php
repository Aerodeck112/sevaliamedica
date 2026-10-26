<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Services\DeliveryDispatcher;
use App\Modules\Notifications\Services\ReminderScanner;
use Illuminate\Console\Command;

/** Rulat din cron la minut: planifică reminderele și trimite livrările scadente, într-un lot limitat. */
class DispatchNotifications extends Command
{
    protected $signature = 'sevalia:notifications:dispatch {--limit= : Numărul maxim de livrări în această rulare} {--fara-remindere : Nu scana reminderele}';

    protected $description = 'Trimite notificările scadente (și planifică reminderele)';

    public function handle(DeliveryDispatcher $dispatcher, ReminderScanner $reminders): int
    {
        $planned = $this->option('fara-remindere') ? 0 : $reminders->run();
        $stats = $dispatcher->run($this->option('limit') ? (int) $this->option('limit') : null);
        $this->info(sprintf(
            'Remindere planificate: %d · trimise: %d · simulate: %d · eșuate: %d · anulate: %d · rezultat necunoscut: %d',
            $planned, $stats['sent'], $stats['simulated'], $stats['failed'], $stats['cancelled'], $stats['unknown'],
        ));

        return self::SUCCESS;
    }
}
