<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Core\Http\Crud\Field;
use App\Core\Http\Crud\ResourceController;
use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class StaffController extends ResourceController
{
    protected function model(): string
    {
        return StaffMember::class;
    }

    protected function routeBase(): string
    {
        return 'panou.clinic.staff';
    }

    protected function titles(): array
    {
        return ['plural' => 'Personal', 'singular' => 'Persoana', 'new' => 'Persoană nouă'];
    }

    protected function description(): ?string
    {
        return 'Medici, terapeuți și asistenți. Legătura cu un cont de utilizator permite programarea și accesul la fișe.';
    }

    protected function query(): Builder
    {
        return StaffMember::query()->with('specialties');
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderBy('last_name')->orderBy('first_name');
    }

    protected function fields(): array
    {
        return [
            Field::make('display_name', 'Nume afișat')->rules('required|string|max:160')->list()->searchable()->hint('Exact cum apare pe site, ex. „dr. Cristina Pop”.'),
            Field::make('kind', 'Categorie', 'select')->rules(['required', Rule::in(array_keys(StaffMember::KINDS))])->options(StaffMember::KINDS)->list(),
            Field::make('honorific', 'Titlu (ex. dr.)')->rules('nullable|string|max:20'),
            Field::make('first_name', 'Prenume')->rules('required|string|max:80')->searchable(),
            Field::make('last_name', 'Nume de familie')->rules('required|string|max:80')->searchable(),
            Field::make('professional_title', 'Titulatură profesională')->rules('nullable|string|max:190')->list(),
            Field::make('stencil_no', 'Parafă')->rules('nullable|string|max:10')->hint('Necesară pentru serviciile raportate la CAS.'),
            Field::make('user_id', 'Cont de utilizator', 'select')->rules('nullable|integer|exists:users,id')
                ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all()),
            Field::make('specialties', 'Specialități', 'multiselect')->relation('specialties')->full()
                ->options(fn () => Specialty::active()->pluck('name', 'id')->all())
                ->list(fn ($s) => $s->specialties->pluck('name')->join(', ')),
            Field::make('locations', 'Locații', 'multiselect')->relation('locations')->full()
                ->options(fn () => Location::active()->pluck('name', 'id')->all()),
            Field::make('calendar_color', 'Culoare în calendar', 'color')->rules(['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'])->hint('Format #RRGGBB'),
            Field::make('site_sort', 'Ordine pe site', 'number')->rules('nullable|integer|min:0|max:999'),
            Field::make('show_on_site', 'Afișat pe site', 'checkbox')->list(),
            Field::make('is_active', 'Activ', 'checkbox')->list(),
            Field::make('bio', 'Prezentare', 'textarea')->rules('nullable|string|max:5000'),
        ];
    }

    protected function prepare(array $data, ?Model $model): array
    {
        $data['site_sort'] = (int) ($data['site_sort'] ?? 0);
        $data['calendar_color'] = $data['calendar_color'] ?: '#00C4C4';
        if (! empty($data['user_id'])) {
            $taken = StaffMember::where('user_id', $data['user_id'])->when($model, fn ($q) => $q->where('id', '!=', $model->id))->exists();
            abort_if($taken, 422, 'Contul este deja asociat altei persoane.');
        }

        return $data;
    }
}
