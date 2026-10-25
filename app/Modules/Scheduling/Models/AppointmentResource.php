<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Clinic\Models\Equipment;
use App\Modules\Clinic\Models\Room;
use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O resursă rezervată de o programare pe intervalul [starts_at, ends_at) (inclusiv pauza de după). */
class AppointmentResource extends Model
{
    public $timestamps = false;

    public const TYPES = ['staff' => 'Persoană', 'room' => 'Sală', 'equipment' => 'Aparat'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'released_at' => 'datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function resourceModel(): ?Model
    {
        return match ($this->resource_type) {
            'staff' => StaffMember::find($this->resource_id),
            'room' => Room::find($this->resource_id),
            'equipment' => Equipment::find($this->resource_id),
            default => null,
        };
    }

    public function resourceName(): string
    {
        $m = $this->resourceModel();

        return $m?->display_name ?? $m?->name ?? ('#'.$this->resource_id);
    }
}
