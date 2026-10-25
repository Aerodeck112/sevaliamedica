<?php

namespace App\Core\Modules;

/**
 * O intrare din meniul panoului. Apare numai dacă utilizatorul are permisiunea cerută.
 */
final class NavItem
{
    /**
     * @param  string|null  $permission  permisiunea necesară (null = orice utilizator autentificat)
     * @param  string  $group  grupul din meniu (ex. „Activitate”, „Site”, „Administrare”)
     * @param  string  $icon  numele pictogramei din resources/views/components/panou/icon.blade.php
     * @param  string|null  $activePattern  tiparul de rută pentru evidențiere (implicit route + '*')
     */
    public function __construct(
        public readonly string $label,
        public readonly string $route,
        public readonly ?string $permission = null,
        public readonly string $group = 'Activitate',
        public readonly string $icon = 'dot',
        public readonly int $order = 100,
        public readonly ?string $activePattern = null,
    ) {}

    public function pattern(): string
    {
        return $this->activePattern ?? preg_replace('/\.index$/', '', $this->route).'*';
    }
}
