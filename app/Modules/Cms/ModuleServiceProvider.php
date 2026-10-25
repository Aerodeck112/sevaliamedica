<?php

namespace App\Modules\Cms;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Identity\Roles;
use App\Modules\Site\Services\PageCache;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'cms';
    }

    public function label(): string
    {
        return 'Site (CMS)';
    }

    public function permissions(): array
    {
        return [
            'cms.edit' => 'Editează paginile și articolele (ciorne)',
            'cms.publish' => 'Publică, retrage și restaurează versiuni ale paginilor',
            'cms.media' => 'Administrează biblioteca media',
            'cms.menus' => 'Administrează meniurile siteului',
            'cms.seo' => 'Modifică datele SEO ale paginilor',
            'cms.redirects' => 'Administrează redirecturile',
            'cms.settings' => 'Modifică setările siteului (contact, rețele sociale, formular, cookie)',
            'cms.tracking' => 'Configurează etichetele de măsurare și marketing (Google, Meta)',
            'cms.submissions' => 'Consultă mesajele primite prin formularele siteului',
        ];
    }

    public function roleGrants(): array
    {
        $editorial = ['cms.edit', 'cms.publish', 'cms.media', 'cms.menus', 'cms.seo', 'cms.redirects', 'cms.settings'];

        return [
            Roles::EDITOR_SITE => $editorial,
            Roles::MANAGER => [...$editorial, 'cms.tracking', 'cms.submissions'],
            Roles::RECEPTIE => ['cms.submissions'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Pagini', 'panou.cms.pages.index', 'cms.edit', 'Site', 'file', 10),
            new NavItem('Articole blog', 'panou.cms.articles.index', 'cms.edit', 'Site', 'clipboard', 11),
            new NavItem('Bibliotecă media', 'panou.cms.media.index', 'cms.media', 'Site', 'image', 12),
            new NavItem('Meniuri', 'panou.cms.menus.index', 'cms.menus', 'Site', 'menu', 13),
            new NavItem('Redirecturi', 'panou.cms.redirects.index', 'cms.redirects', 'Site', 'link', 14),
            new NavItem('Setări site', 'panou.cms.settings.edit', 'cms.settings', 'Site', 'settings', 15),
            new NavItem('Etichete și cookie', 'panou.cms.tracking.edit', 'cms.tracking', 'Site', 'shield', 16),
            new NavItem('Mesaje din site', 'panou.cms.submissions.index', 'cms.submissions', 'Activitate', 'inbox', 40),
        ];
    }

    public function boot(): void
    {
        parent::boot();

        // Datele structurate afișate pe site invalidează cache-ul paginilor publice.
        foreach ([StaffMember::class, Location::class, Specialty::class, MenuItem::class] as $model) {
            $model::saved(fn () => PageCache::flush());
            $model::deleted(fn () => PageCache::flush());
        }
    }
}
