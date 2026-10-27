<?php

namespace App\Modules\Kiosk;

use App\Core\Modules\ModuleServiceProvider as BaseProvider;
use App\Core\Modules\NavItem;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Kiosk\Dashboard\ReviewsWidget;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Models\KioskSession;
use App\Modules\Kiosk\Models\PatientReview;
use App\Modules\Medical\Models\ConsentTemplate;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientTab;
use App\Modules\Patients\Support\PatientTabRegistry;
use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Console\Scheduling\Schedule;

class ModuleServiceProvider extends BaseProvider
{
    public function key(): string
    {
        return 'kiosk';
    }

    public function label(): string
    {
        return 'Tablete recepție';
    }

    public function permissions(): array
    {
        return [
            'kiosk.devices' => 'Adaugă, asociază și deconectează tabletele de la recepție',
            'kiosk.consent.send' => 'Trimite consimțăminte spre semnare pe tabletă',
            'kiosk.review.send' => 'Cere recenzii pe tabletă după serviciile efectuate',
            'reviews.view' => 'Consultă recenziile pacienților și statisticile pe medici și servicii',
            'reviews.moderate' => 'Publică pe site recenziile pentru care pacientul și-a dat acordul',
        ];
    }

    public function roleGrants(): array
    {
        return [
            Roles::RECEPTIE => ['kiosk.consent.send', 'kiosk.review.send'],
            Roles::MEDIC => ['kiosk.consent.send', 'kiosk.review.send'],
            Roles::TERAPEUT => ['kiosk.consent.send', 'kiosk.review.send'],
            Roles::ASISTENT => ['kiosk.consent.send', 'kiosk.review.send'],
            Roles::MANAGER => ['kiosk.devices', 'kiosk.consent.send', 'kiosk.review.send', 'reviews.view', 'reviews.moderate'],
            Roles::ADMIN_TEHNIC => ['kiosk.devices'],
            Roles::EDITOR_SITE => ['reviews.moderate', 'reviews.view'],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavItem('Recenzii', 'panou.kiosk.reviews', 'reviews.view', 'Rapoarte', 'chart', 20),
            new NavItem('Tablete recepție', 'panou.kiosk.devices', 'kiosk.devices', 'Administrare', 'clipboard', 40),
        ];
    }

    public function dashboardWidgets(): array
    {
        return [new ReviewsWidget];
    }

    public function schedule(Schedule $schedule): void
    {
        // Cererile expirate se marchează, ca listele să fie corecte (codurile expirate sunt oricum refuzate).
        $schedule->call(fn () => KioskSession::whereIn('status', ['in_asteptare', 'deschis'])->where('expires_at', '<', now())
            ->update(['status' => 'expirat']))->everyFiveMinutes()->name('kiosk-expira')->withoutOverlapping();
    }

    public function boot(): void
    {
        parent::boot();
        $this->app->make(PatientTabRegistry::class)->add(new PatientTab('tableta', 'Tabletă', 'kiosk.consent.send', 60,
            fn (Patient $p, User $u) => view('kiosk::panel.patient-tab', [
                'patient' => $p,
                'user' => $u,
                'templates' => ConsentTemplate::where('is_active', true)->with('latestVersion')->orderBy('name')->get()->filter->isReady(),
                'devices' => KioskDevice::whereNull('revoked_at')->whereNotNull('paired_at')->orderBy('name')->get(),
                'sessions' => KioskSession::where('patient_id', $p->id)->with(['consentVersion.template', 'staffMember', 'service', 'creator'])->latest()->limit(20)->get(),
                'records' => ServiceRecord::where('patient_id', $p->id)->where('status', 'efectuat')->with(['service', 'staffMember'])
                    ->latest('performed_at')->limit(10)->get(),
                'reviewed' => PatientReview::where('patient_id', $p->id)->pluck('service_record_id')->filter()->all(),
            ])));
    }
}
