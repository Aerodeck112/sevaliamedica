<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Audit\Audit;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PanelStages;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Administrare → Funcții panou: etapele active ale panoului (permisiunea settings.manage, auditat). */
class PanelFeaturesController extends Controller
{
    public function edit(ModuleRegistry $registry, PanelStages $stages): View
    {
        // Numele elementelor de meniu din fiecare etapă, ca administratorul să vadă ce activează.
        $labels = [];
        foreach ($registry->all() as $module) {
            foreach ($module->navigation() as $item) {
                $labels[$item->route] = $item->label;
            }
        }

        return view('identity::features', [
            'stages' => collect(PanelStages::stages())->map(fn ($s) => $s + ['items' => array_values(array_filter(array_map(fn ($r) => $labels[$r] ?? null, $s['routes'])))]),
            'enabled' => $stages->enabled(),
        ]);
    }

    public function update(Request $request, PanelStages $stages): RedirectResponse
    {
        $data = $request->validate(['stages' => ['array'], 'stages.*' => ['integer', 'in:'.implode(',', array_keys(PanelStages::stages()))]]);
        $before = $stages->enabled();
        $stages->set((array) ($data['stages'] ?? []));
        Audit::log('panel.stages_updated', context: ['inainte' => $before, 'dupa' => $stages->enabled()]);

        return back()->with('status', 'Funcțiile panoului au fost actualizate.');
    }
}
