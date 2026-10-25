<?php

namespace App\Modules\Dashboard;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'dashboard';
    }

    public function label(): string
    {
        return 'Ecran principal';
    }

    public function navigation(): array
    {
        return [new NavItem('Acasă', 'panou.dashboard', null, 'Activitate', 'home', 0, 'panou.dashboard')];
    }
}
