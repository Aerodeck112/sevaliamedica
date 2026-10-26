<?php

namespace App\Modules\Notifications;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Notifications\Dashboard\FailedNotificationsWidget;
use App\Modules\Notifications\Listeners\AppointmentNotificationSubscriber;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Services\ChannelManager;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientMergeRegistry;
use App\Modules\Patients\Support\PatientTab;
use App\Modules\Patients\Support\PatientTabRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'notifications';
    }

    public function label(): string
    {
        return 'Notificări';
    }

    public function permissions(): array
    {
        return [
            'notifications.view' => 'Vede jurnalul livrărilor de notificări',
            'notifications.retry' => 'Reîncearcă manual o notificare eșuată',
            'notifications.send' => 'Trimite manual mesaje administrative (follow-up) din șabloane',
            'notifications.templates' => 'Editează șabloanele de mesaje',
            'notifications.settings' => 'Configurează canalele (e-mail, SMS, WhatsApp) și regulile de trimitere',
            'feedback.view' => 'Vede răspunsurile la solicitările de feedback',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::RECEPTIE => ['notifications.view', 'notifications.retry', 'notifications.send'],
            Roles::MANAGER => array_keys($this->permissions()),
            Roles::ADMIN_TEHNIC => ['notifications.settings'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Notificări', 'panou.notifications.deliveries', 'notifications.view', 'Activitate', 'bell', 20, 'panou.notifications.deliveries*'),
            new NavItem('Feedback pacienți', 'panou.notifications.feedback', 'feedback.view', 'Rapoarte', 'inbox', 40),
            new NavItem('Șabloane mesaje', 'panou.notifications.templates', 'notifications.templates', 'Administrare', 'send', 38, 'panou.notifications.templates*'),
            new NavItem('Canale notificări', 'panou.notifications.channels', 'notifications.settings', 'Administrare', 'bell', 39, 'panou.notifications.channels*'),
        ];
    }

    public function dashboardWidgets(): array
    {
        return [new FailedNotificationsWidget];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('sevalia:notifications:dispatch')->everyMinute()->withoutOverlapping(10)->name('notifications:dispatch');
    }

    public function register(): void
    {
        parent::register();
        $this->app->singleton(ChannelManager::class);
    }

    public function boot(): void
    {
        parent::boot();
        Event::subscribe(AppointmentNotificationSubscriber::class);

        $this->app->make(PatientTabRegistry::class)->add(new PatientTab('notificari', 'Mesaje', 'notifications.view', 60,
            fn (Patient $p, User $u) => view('notifications::patient-tab', [
                'patient' => $p,
                'deliveries' => NotificationDelivery::where('patient_id', $p->id)->latest('id')->limit(50)->get(),
            ])));
        $this->app->make(PatientMergeRegistry::class)->add(PatientMergeRegistry::table('Notificări', 'notification_deliveries'));
        $this->app->make(PatientMergeRegistry::class)->add(PatientMergeRegistry::table('Feedback', 'feedback_requests'));
    }
}
