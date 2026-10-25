<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Core\Http\Crud\Field;
use App\Core\Http\Crud\ResourceController;
use App\Modules\Clinic\Models\Specialty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SpecialtyController extends ResourceController
{
    protected function model(): string
    {
        return Specialty::class;
    }

    protected function routeBase(): string
    {
        return 'panou.clinic.specialties';
    }

    protected function titles(): array
    {
        return ['plural' => 'Specialități', 'singular' => 'Specialitatea', 'new' => 'Specialitate nouă'];
    }

    protected function description(): ?string
    {
        return 'Specialitățile confirmate ale clinicii. Fișele medicale și raportările se configurează pe aceste specialități.';
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    protected function fields(): array
    {
        return [
            Field::make('name', 'Denumire')->rules('required|string|max:120')->list()->searchable(),
            Field::make('cas_code', 'Cod specialitate CNAS')->rules('nullable|string|max:20')->list()->hint('Din nomenclatorul oficial; lăsați gol dacă nu este confirmat.'),
            Field::make('sort', 'Ordine', 'number')->rules('nullable|integer|min:0|max:999'),
            Field::make('is_active', 'Activă', 'checkbox')->list(),
            Field::make('description', 'Descriere internă', 'textarea')->rules('nullable|string|max:2000'),
        ];
    }

    protected function prepare(array $data, ?Model $model): array
    {
        $data['slug'] = $model?->slug ?? Str::slug($data['name']);
        $data['sort'] = (int) ($data['sort'] ?? 0);

        return $data;
    }
}
