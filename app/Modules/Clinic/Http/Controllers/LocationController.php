<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Core\Http\Crud\Field;
use App\Core\Http\Crud\ResourceController;
use App\Modules\Clinic\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LocationController extends ResourceController
{
    protected function model(): string
    {
        return Location::class;
    }

    protected function routeBase(): string
    {
        return 'panou.clinic.locations';
    }

    protected function titles(): array
    {
        return ['plural' => 'Locații', 'singular' => 'Locația', 'new' => 'Locație nouă'];
    }

    protected function description(): ?string
    {
        return 'Punctele de lucru, cu adresa și programul afișate pe site.';
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    protected function fields(): array
    {
        return [
            Field::make('name', 'Denumire')->rules('required|string|max:120')->list()->searchable(),
            Field::make('address', 'Adresă')->rules('nullable|string|max:255')->list(),
            Field::make('city', 'Localitate')->rules('nullable|string|max:120'),
            Field::make('county', 'Județ')->rules('nullable|string|max:80'),
            Field::make('phone', 'Telefon', 'tel')->rules('nullable|string|max:40')->list(),
            Field::make('email', 'E-mail', 'email')->rules('nullable|email|max:190'),
            Field::make('map_query', 'Adresă pentru hartă')->rules('nullable|string|max:255')->hint('Textul căutat în Google Maps pentru harta încorporată.'),
            Field::make('cas_work_point_code', 'Cod punct de lucru CAS')->rules('nullable|string|max:40')->hint('Doar dacă punctul de lucru este contractat; din contract.'),
            Field::make('sort', 'Ordine', 'number')->rules('nullable|integer|min:0|max:999'),
            Field::make('is_active', 'Activă', 'checkbox')->list(),
            Field::make('opening_hours', 'Program de funcționare', 'hours')->rules('nullable|array'),
        ];
    }

    protected function prepare(array $data, ?Model $model): array
    {
        $data['slug'] = $model?->slug ?? Str::slug($data['name']);
        $data['sort'] = (int) ($data['sort'] ?? 0);
        $hours = [];
        foreach ((array) ($data['opening_hours'] ?? []) as $day => $intervals) {
            foreach ((array) $intervals as $interval) {
                [$from, $to] = [$interval[0] ?? null, $interval[1] ?? null];
                if ($from && $to && $from < $to) {
                    $hours[(string) $day][] = [$from, $to];
                }
            }
        }
        $data['opening_hours'] = $hours;

        return $data;
    }
}
