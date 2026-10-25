<?php

namespace App\Core\Modules;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Registrul modulelor încărcate. Furnizorii se înregistrează singuri în register().
 */
class ModuleRegistry
{
    /** Ordinea grupurilor în meniul panoului. */
    public const GROUP_ORDER = ['Activitate', 'Medical', 'CAS', 'Financiar', 'Rapoarte', 'Site', 'Administrare'];

    /** @var array<string, ModuleServiceProvider> */
    private array $modules = [];

    public function add(ModuleServiceProvider $module): void
    {
        $this->modules[$module->key()] = $module;
    }

    /** @return array<string, ModuleServiceProvider> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return array<string, array{module: string, label: string}> */
    public function permissions(): array
    {
        $out = [];
        foreach ($this->modules as $module) {
            foreach ($module->permissions() as $name => $label) {
                $out[$name] = ['module' => $module->label(), 'label' => $label];
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    public function roleGrants(): array
    {
        $out = [];
        foreach ($this->modules as $module) {
            foreach ($module->roleGrants() as $role => $permissions) {
                $out[$role] = array_values(array_unique([...($out[$role] ?? []), ...$permissions]));
            }
        }

        return $out;
    }

    /** @return list<DashboardWidget> */
    public function widgetsFor(\App\Models\User $user): array
    {
        $widgets = [];
        foreach ($this->modules as $module) {
            foreach ($module->dashboardWidgets() as $widget) {
                if ($widget->visibleTo($user)) {
                    $widgets[] = $widget;
                }
            }
        }
        usort($widgets, fn (DashboardWidget $a, DashboardWidget $b) => $a->order() <=> $b->order());

        return $widgets;
    }

    /**
     * Meniul grupat, filtrat după permisiunile utilizatorului.
     *
     * @return array<string, list<NavItem>>
     */
    public function navigationFor(?Authenticatable $user): array
    {
        $groups = [];
        foreach ($this->modules as $module) {
            foreach ($module->navigation() as $item) {
                if ($item->permission !== null && (! $user || ! $user->can($item->permission))) {
                    continue;
                }
                $groups[$item->group][] = $item;
            }
        }

        foreach ($groups as &$items) {
            usort($items, fn (NavItem $a, NavItem $b) => [$a->order, $a->label] <=> [$b->order, $b->label]);
        }
        unset($items);

        uksort($groups, function (string $a, string $b) {
            $ia = array_search($a, self::GROUP_ORDER, true);
            $ib = array_search($b, self::GROUP_ORDER, true);

            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });

        return $groups;
    }
}
