<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Core\Http\Crud\Field;
use App\Core\Http\Crud\ResourceController;
use App\Modules\Clinic\Models\Equipment;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Room;
use Illuminate\Database\Eloquent\Builder;

class EquipmentController extends ResourceController
{
    protected function model(): string
    {
        return Equipment::class;
    }

    protected function routeBase(): string
    {
        return 'panou.clinic.equipment';
    }

    protected function titles(): array
    {
        return ['plural' => 'Aparatură', 'singular' => 'Aparatul', 'new' => 'Aparat nou'];
    }

    protected function description(): ?string
    {
        return 'Fiecare aparat fizic este o înregistrare. Un aparat nu poate fi rezervat în două programări simultane.';
    }

    protected function query(): Builder
    {
        return Equipment::query()->with(['location', 'room']);
    }

    protected function fields(): array
    {
        return [
            Field::make('name', 'Denumire')->rules('required|string|max:120')->list()->searchable(),
            Field::make('kind', 'Tip')->rules('required|string|max:60|alpha_dash')->list()->hint('Cheie scurtă folosită de cerințele serviciilor, ex. alterg.'),
            Field::make('location_id', 'Locație', 'select')->rules('required|integer|exists:locations,id')
                ->options(fn () => Location::active()->pluck('name', 'id')->all())->list(fn ($e) => $e->location?->name),
            Field::make('room_id', 'Sală (dacă e fix)', 'select')->rules('nullable|integer|exists:rooms,id')
                ->options(fn () => Room::orderBy('name')->pluck('name', 'id')->all())->list(fn ($e) => $e->room?->name),
            Field::make('serial', 'Serie')->rules('nullable|string|max:80'),
            Field::make('is_active', 'Activ', 'checkbox')->list(),
        ];
    }
}
