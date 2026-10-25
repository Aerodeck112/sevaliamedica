<?php

namespace App\Core\Console;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Identity\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creează primul cont de administrator tehnic la instalare. Parola se cere interactiv (nu apare în istoric).
 */
class CreateAdmin extends Command
{
    protected $signature = 'sevalia:create-admin {email} {--name=Administrator tehnic}';

    protected $description = 'Creează un cont de administrator tehnic';

    public function handle(): int
    {
        $this->call('sevalia:sync-permissions');

        $email = Str::lower($this->argument('email'));
        if (User::where('email', $email)->exists()) {
            $this->error('Există deja un cont cu această adresă.');

            return self::FAILURE;
        }

        $password = $this->secret('Parola (minimum '.config('sevalia.security.password_min_length').' caractere)');
        if (mb_strlen((string) $password) < (int) config('sevalia.security.password_min_length') || ! preg_match('/\d/', $password) || ! preg_match('/\pL/u', $password)) {
            $this->error('Parola trebuie să aibă lungimea minimă și să conțină litere și cifre.');

            return self::FAILURE;
        }

        $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'is_active' => true]);
        $user->forceFill(['password_changed_at' => now()])->save();
        $user->assignRole(Roles::ADMIN_TEHNIC);
        Audit::log('users.created', $user, ['roluri' => [Roles::ADMIN_TEHNIC], 'sursa' => 'consola']);

        $this->info("Contul {$email} a fost creat. La prima autentificare se configurează MFA.");

        return self::SUCCESS;
    }
}
