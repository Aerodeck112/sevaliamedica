<?php

namespace App\Modules\Marketing\Dashboard;

use App\Core\Modules\DashboardWidget;
use App\Models\User;
use App\Modules\Marketing\Models\SeoAuditRun;
use Illuminate\Contracts\View\View;

class SeoWidget extends DashboardWidget
{
    public function permission(): string
    {
        return 'marketing.seo';
    }

    public function order(): int
    {
        return 70;
    }

    public function render(User $user): View
    {
        $runs = SeoAuditRun::where('status', 'finalizat')->latest('finished_at')->limit(2)->get();

        return view('marketing::widget', ['run' => $runs->first(), 'previous' => $runs->get(1)]);
    }
}
