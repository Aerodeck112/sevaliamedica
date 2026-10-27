<?php

namespace App\Modules\Marketing;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Modules\Cms\Events\PageVisibilityChanged;
use App\Modules\Identity\Roles;
use App\Modules\Marketing\Dashboard\SeoWidget;
use App\Modules\Marketing\Services\IndexNowService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'marketing';
    }

    public function label(): string
    {
        return 'Marketing și SEO';
    }

    public function permissions(): array
    {
        return [
            'marketing.seo' => 'Consultă auditul SEO automat, îl pornește și administrează cuvintele cheie urmărite',
            'marketing.integrations' => 'Configurează Google Search Console, Google Business, IndexNow și vizibilitatea în asistenții AI',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::MANAGER => ['marketing.seo', 'marketing.integrations'],
            Roles::EDITOR_SITE => ['marketing.seo'],
            Roles::ADMIN_TEHNIC => ['marketing.seo', 'marketing.integrations'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Audit SEO', 'panou.marketing.seo', 'marketing.seo', 'Marketing', 'search', 10),
            new NavItem('Cuvinte cheie', 'panou.marketing.keywords', 'marketing.seo', 'Marketing', 'chart', 20),
            new NavItem('Google și AI', 'panou.marketing.integrations', 'marketing.integrations', 'Marketing', 'globe', 30),
        ];
    }

    public function dashboardWidgets(): array
    {
        return [new SeoWidget];
    }

    public function schedule(Schedule $schedule): void
    {
        // Pilotul automat: o rulare completă în fiecare noapte, continuată pe loturi la fiecare minut.
        $schedule->command('sevalia:seo-audit --start')->dailyAt('03:17')->name('seo-audit-start')->withoutOverlapping(30);
        $schedule->command('sevalia:seo-audit')->everyMinute()->name('seo-audit-continua')->withoutOverlapping(10);
        $schedule->call(fn () => app(IndexNowService::class)->flush())->everyFiveMinutes()->name('indexnow')->withoutOverlapping(10);
    }

    public function boot(): void
    {
        parent::boot();
        Event::listen(PageVisibilityChanged::class, fn (PageVisibilityChanged $e) => app(IndexNowService::class)->queue($e->page->path));
    }
}
