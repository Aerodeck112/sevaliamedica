<?php

namespace App\Modules\Kiosk\Dashboard;

use App\Core\Modules\DashboardWidget;
use App\Models\User;
use App\Modules\Kiosk\Models\PatientReview;
use Illuminate\Contracts\View\View;

class ReviewsWidget extends DashboardWidget
{
    public function permission(): string
    {
        return 'reviews.view';
    }

    public function order(): int
    {
        return 60;
    }

    public function render(User $user): View
    {
        $since = now()->subDays(30);

        return view('kiosk::panel.widget', [
            'count' => PatientReview::where('created_at', '>=', $since)->count(),
            'average' => PatientReview::where('created_at', '>=', $since)->avg('rating'),
            'low' => PatientReview::where('created_at', '>=', $since)->where('rating', '<=', 2)->count(),
        ]);
    }
}
