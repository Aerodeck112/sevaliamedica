<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Core\Modules\ModuleRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ModuleRegistry $registry): View
    {
        $user = $request->user();

        return view('dashboard::index', [
            'user' => $user,
            'widgets' => $registry->widgetsFor($user),
            'shortcuts' => collect($registry->navigationFor($user))->flatten()->reject(fn ($i) => $i->route === 'panou.dashboard'),
        ]);
    }
}
