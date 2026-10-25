<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Excepție de la program: concediu / liber (indisponibil), ore suplimentare (disponibil în plus),
 * închidere (fără staff_member_id: întreaga locație sau, fără locație, întreaga clinică).
 */
class ScheduleException extends Model
{
    public const KINDS = [
        'concediu' => 'Concediu',
        'liber' => 'Liber / indisponibil',
        'ore_suplimentare' => 'Ore suplimentare',
        'inchidere' => 'Închidere locație / clinică',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isUnavailability(): bool
    {
        return $this->kind !== 'ore_suplimentare';
    }
}
