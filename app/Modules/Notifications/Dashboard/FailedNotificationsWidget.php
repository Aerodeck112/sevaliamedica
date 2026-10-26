<?php

namespace App\Modules\Notifications\Dashboard;

use App\Core\Modules\DashboardWidget;
use App\Models\User;
use App\Modules\Identity\Roles;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Contracts\View\View;

/** Recepție / manager: notificări eșuate sau cu rezultat necunoscut, de rezolvat. */
class FailedNotificationsWidget extends DashboardWidget
{
    public function permission(): string
    {
        return 'notifications.view';
    }

    public function roles(): array
    {
        return [Roles::RECEPTIE, Roles::MANAGER];
    }

    public function order(): int
    {
        return 15;
    }

    public function render(User $user): View
    {
        $problems = NotificationDelivery::whereIn('status', [NotificationDelivery::FAILED_FINAL, NotificationDelivery::UNKNOWN])
            ->where('created_at', '>=', now()->subDays(7));

        return view('notifications::dashboard.failed', [
            'count' => (clone $problems)->count(),
            'retrying' => NotificationDelivery::where('status', NotificationDelivery::FAILED)->count(),
            'latest' => $problems->with('patient')->latest('id')->limit(5)->get(),
            'pending' => NotificationDelivery::where('status', NotificationDelivery::PENDING)->count(),
        ]);
    }
}
