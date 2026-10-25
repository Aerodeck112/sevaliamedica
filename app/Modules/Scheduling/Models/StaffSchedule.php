<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Programul de lucru săptămânal al unei persoane într-o locație, pe o perioadă de valabilitate. */
class StaffSchedule extends Model
{
    public const WEEKDAYS = [1 => 'Luni', 2 => 'Marți', 3 => 'Miercuri', 4 => 'Joi', 5 => 'Vineri', 6 => 'Sâmbătă', 7 => 'Duminică'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['breaks' => 'array', 'valid_from' => 'date', 'valid_until' => 'date'];
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
