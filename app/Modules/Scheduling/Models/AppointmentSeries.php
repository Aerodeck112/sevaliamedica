<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O serie de ședințe (programări repetate). Modulul Rehab își leagă planurile de serie prin series_id.
 */
class AppointmentSeries extends Model
{
    protected $table = 'appointment_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rule' => 'array'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'series_id')->orderBy('starts_at');
    }

    /**
     * Evidența ședințelor: planificate (în viitor, active), efectuate (finalizate), anulate/neprezentate, rămase.
     *
     * @return array{planned:int, done:int, cancelled:int, no_show:int, active:int, remaining:int}
     */
    public function counts(): array
    {
        $rows = $this->appointments()->get(['status', 'starts_at']);
        $done = $rows->where('status', AppointmentStatus::FINALIZATA)->count();
        $cancelled = $rows->where('status', AppointmentStatus::ANULATA)->count();
        $noShow = $rows->where('status', AppointmentStatus::NEPREZENTAT)->count();
        $active = $rows->whereIn('status', [AppointmentStatus::CONFIRMATA, AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE])->count();

        return [
            'planned' => $this->planned_count,
            'done' => $done,
            'cancelled' => $cancelled,
            'no_show' => $noShow,
            'active' => $active,
            'remaining' => max(0, $this->planned_count - $done),
        ];
    }
}
