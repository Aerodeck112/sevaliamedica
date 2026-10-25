<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Services\SchedulingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Regulile de programare și acces, editabile de clinică (permisiunea scheduling.settings, auditat). */
class SettingsController extends Controller
{
    public function edit(SchedulingSettings $settings): View
    {
        return view('scheduling::settings', [
            'groups' => collect(SchedulingSettings::definitions())->map(fn ($d, $k) => $d + ['key' => $k, 'field' => self::field($k)])->groupBy('group'),
            'values' => $settings->all(),
        ]);
    }

    public function update(Request $request, SchedulingSettings $settings): RedirectResponse
    {
        $rules = [];
        foreach (SchedulingSettings::definitions() as $key => $def) {
            $rules[self::field($key)] = $def['rules'];
        }
        $data = $request->validate($rules);
        $values = [];
        foreach (array_keys(SchedulingSettings::definitions()) as $key) {
            if (array_key_exists(self::field($key), $data)) {
                $values[$key] = $data[self::field($key)];
            }
        }
        $changed = $settings->update($values);

        return back()->with('status', $changed ? 'Regulile au fost salvate ('.count($changed).' modificări, înregistrate în audit).' : 'Nicio modificare.');
    }

    public static function field(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
