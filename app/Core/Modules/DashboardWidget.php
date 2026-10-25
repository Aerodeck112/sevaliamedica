<?php

namespace App\Core\Modules;

use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * Un bloc din ecranul principal al panoului. Modulele își declară widgeturile în dashboardWidgets().
 * Ecranul principal afișează numai widgeturile vizibile utilizatorului curent, ordonate după order().
 */
abstract class DashboardWidget
{
    /** Permisiunea necesară. */
    abstract public function permission(): string;

    abstract public function render(User $user): View;

    /** Rolurile pentru care widgetul e relevant (gol = orice rol cu permisiunea). */
    public function roles(): array
    {
        return [];
    }

    public function order(): int
    {
        return 100;
    }

    /** Lățimea în grilă: 1 (o coloană) sau 2 (lățime dublă). */
    public function span(): int
    {
        return 1;
    }

    public function visibleTo(User $user): bool
    {
        if (! $user->can($this->permission())) {
            return false;
        }

        return $this->roles() === [] || $user->hasAnyRole($this->roles());
    }
}
