<?php

namespace App\Modules\Clinic;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Modules\Identity\Roles;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'clinic';
    }

    public function label(): string
    {
        return 'Clinică';
    }

    public function permissions(): array
    {
        return [
            'clinic.manage' => 'Administrează locații, specialități, personal, săli, aparatură și catalogul de servicii',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::MANAGER => ['clinic.manage'],
            Roles::ADMIN_TEHNIC => ['clinic.manage'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Locații', 'panou.clinic.locations.index', 'clinic.manage', 'Administrare', 'globe', 30),
            new NavItem('Specialități', 'panou.clinic.specialties.index', 'clinic.manage', 'Administrare', 'layers', 31),
            new NavItem('Personal', 'panou.clinic.staff.index', 'clinic.manage', 'Administrare', 'users', 32),
            new NavItem('Săli', 'panou.clinic.rooms.index', 'clinic.manage', 'Administrare', 'home', 33),
            new NavItem('Aparatură', 'panou.clinic.equipment.index', 'clinic.manage', 'Administrare', 'activity', 34),
            new NavItem('Catalog servicii', 'panou.clinic.services.index', 'clinic.manage', 'Administrare', 'clipboard', 35),
        ];
    }
}
