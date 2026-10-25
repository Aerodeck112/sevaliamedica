<?php

namespace App\Core\Console;

use App\Core\Modules\ModuleRegistry;
use App\Modules\Identity\Roles;
use Illuminate\Console\Command;

/**
 * Generează docs/ROLURI-PERMISIUNI.md din permisiunile și acordările implicite declarate de module.
 */
class PermissionsMatrix extends Command
{
    protected $signature = 'sevalia:permissions-matrix {--path=docs/ROLURI-PERMISIUNI.md}';

    protected $description = 'Generează matricea rolurilor și permisiunilor';

    public function handle(ModuleRegistry $registry): int
    {
        $roles = Roles::all();
        $grants = $registry->roleGrants();
        $lines = [
            '# Matricea rolurilor și permisiunilor',
            '',
            'Document generat de `php artisan sevalia:permissions-matrix` din declarațiile modulelor. Nu editați manual.',
            '',
            'Acordările de mai jos sunt cele **implicite**. Administratorul poate ajusta rolurile din panou; verificarea',
            'se face pe server la fiecare acțiune. Accesul la datele medicale ale unui pacient cere, în plus față de',
            'permisiune, o relație de îngrijire (vezi `SECURITATE.md`). Administratorul tehnic nu are implicit acces medical.',
            '',
            '| Modul | Permisiune | Descriere | '.implode(' | ', $roles).' |',
            '|---|---|---|'.str_repeat('---|', count($roles)),
        ];
        foreach ($registry->permissions() as $name => $info) {
            $cells = [];
            foreach (array_keys($roles) as $role) {
                $cells[] = in_array($name, $grants[$role] ?? [], true) ? '✔' : '';
            }
            $lines[] = "| {$info['module']} | `{$name}` | {$info['label']} | ".implode(' | ', $cells).' |';
        }

        file_put_contents(base_path($this->option('path')), implode("\n", $lines)."\n");
        $this->info('Scris: '.$this->option('path'));

        return self::SUCCESS;
    }
}
