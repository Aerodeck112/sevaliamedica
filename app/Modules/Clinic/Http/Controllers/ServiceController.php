<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Core\Audit\Audit;
use App\Core\Http\Crud\Field;
use App\Core\Http\Crud\ResourceController;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceController extends ResourceController
{
    protected function model(): string
    {
        return Service::class;
    }

    protected function routeBase(): string
    {
        return 'panou.clinic.services';
    }

    protected function titles(): array
    {
        return ['plural' => 'Catalog servicii', 'singular' => 'Serviciul', 'new' => 'Serviciu nou'];
    }

    protected function description(): ?string
    {
        return 'Durate, resurse necesare și tarife private. Un tarif necompletat înseamnă „neconfirmat” și nu se afișează.';
    }

    protected function query(): Builder
    {
        return Service::query()->with(['specialty', 'requirements']);
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    protected function fields(): array
    {
        return [
            Field::make('name', 'Denumire')->rules('required|string|max:190')->list()->searchable(),
            Field::make('code', 'Cod intern')->rules('required|string|max:40|alpha_dash')->list()->searchable(),
            Field::make('kind', 'Tip', 'select')->rules(['required', Rule::in(array_keys(Service::KINDS))])->options(Service::KINDS)->list(),
            Field::make('specialty_id', 'Specialitate', 'select')->rules('nullable|integer|exists:specialties,id')
                ->options(fn () => Specialty::active()->pluck('name', 'id')->all())->list(fn ($s) => $s->specialty?->name),
            Field::make('staff_kind', 'Efectuat de', 'select')->rules(['nullable', Rule::in(array_keys(StaffMember::KINDS))])->options(StaffMember::KINDS),
            Field::make('duration_minutes', 'Durată (minute)', 'number')->rules('required|integer|min:5|max:600')->list(),
            Field::make('buffer_minutes', 'Pauză după (minute)', 'number')->rules('nullable|integer|min:0|max:120'),
            Field::make('price', 'Tarif privat (lei)', 'number')->rules('nullable|numeric|min:0|max:100000')
                ->list(fn ($s) => $s->price !== null ? number_format((float) $s->price, 2, ',', '.').' lei' : 'neconfirmat'),
            Field::make('price_note', 'Notă tarif')->rules('nullable|string|max:190'),
            Field::make('cas_reportable', 'Poate fi decontat CAS', 'checkbox')->hint('Numai pentru servicii din contractul CAS confirmat.'),
            Field::make('cas_code', 'Cod serviciu CNAS')->rules('nullable|string|max:40'),
            Field::make('online_booking', 'Poate fi solicitat de pe site', 'checkbox'),
            Field::make('online_auto_confirm', 'Confirmare automată online', 'checkbox')->hint('Doar dacă regulile de disponibilitate permit; altfel solicitarea așteaptă recepția.'),
            Field::make('is_active', 'Activ', 'checkbox')->list(),
        ];
    }

    protected function form(\Illuminate\Database\Eloquent\Model $model): View
    {
        return parent::form($model)->with('extra', $model->exists ? view('clinic::service-requirements', ['service' => $model]) : null);
    }

    public function storeRequirement(Request $request, int $id): RedirectResponse
    {
        $service = Service::findOrFail($id);
        $data = $request->validate([
            'resource_type' => ['required', Rule::in(['room', 'equipment'])],
            'resource_kind' => ['required', 'string', 'max:60', 'alpha_dash'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        $service->requirements()->updateOrCreate(
            ['resource_type' => $data['resource_type'], 'resource_kind' => $data['resource_kind']],
            ['quantity' => $data['quantity']],
        );
        Audit::log('clinic.services.requirement_set', $service, $data);

        return back()->with('status', 'Cerința de resurse a fost salvată.');
    }

    public function destroyRequirement(int $id, int $requirement): RedirectResponse
    {
        $service = Service::findOrFail($id);
        $service->requirements()->whereKey($requirement)->delete();
        Audit::log('clinic.services.requirement_removed', $service, ['requirement' => $requirement]);

        return back()->with('status', 'Cerința a fost eliminată.');
    }
}
