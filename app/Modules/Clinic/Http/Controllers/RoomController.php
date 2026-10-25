<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Core\Http\Crud\Field;
use App\Core\Http\Crud\ResourceController;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Room;
use Illuminate\Database\Eloquent\Builder;

class RoomController extends ResourceController
{
    protected function model(): string
    {
        return Room::class;
    }

    protected function routeBase(): string
    {
        return 'panou.clinic.rooms';
    }

    protected function titles(): array
    {
        return ['plural' => 'Săli și cabinete', 'singular' => 'Sala', 'new' => 'Sală nouă'];
    }

    protected function description(): ?string
    {
        return 'Spațiile care se rezervă împreună cu personalul. Capacitatea = câte programări pot avea loc simultan.';
    }

    protected function query(): Builder
    {
        return Room::query()->with('location');
    }

    protected function fields(): array
    {
        return [
            Field::make('name', 'Denumire')->rules('required|string|max:120')->list()->searchable(),
            Field::make('location_id', 'Locație', 'select')->rules('required|integer|exists:locations,id')
                ->options(fn () => Location::active()->pluck('name', 'id')->all())->list(fn ($r) => $r->location?->name),
            Field::make('kind', 'Tip')->rules('nullable|string|max:60|alpha_dash')->list()->hint('Cheie scurtă folosită de cerințele serviciilor, ex. kinetoterapie.'),
            Field::make('capacity', 'Capacitate', 'number')->rules('required|integer|min:1|max:50')->list(),
            Field::make('is_active', 'Activă', 'checkbox')->list(),
        ];
    }
}
