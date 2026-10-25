<?php

namespace App\Modules\Identity;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'identity';
    }

    public function label(): string
    {
        return 'Conturi și securitate';
    }

    public function permissions(): array
    {
        return [
            'users.manage' => 'Administrează conturile de utilizator și rolurile',
            'audit.view' => 'Consultă jurnalul de audit',
            'settings.manage' => 'Modifică setările generale ale aplicației',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::ADMIN_TEHNIC => ['users.manage', 'audit.view', 'settings.manage'],
            Roles::MANAGER => ['audit.view'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Utilizatori', 'panou.users.index', 'users.manage', 'Administrare', 'users', 10),
            new NavItem('Jurnal de audit', 'panou.audit.index', 'audit.view', 'Administrare', 'shield', 20),
        ];
    }
}
