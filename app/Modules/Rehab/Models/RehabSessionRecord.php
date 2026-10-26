<?php

namespace App\Modules\Rehab\Models;

use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ședință efectuată, marcată explicit de terapeut. Legată de programare și de serviciul efectuat (service_records).
 * Dacă serviciul efectuat este ulterior anulat/corectat (în modulul de programări), ședința nu mai contează ca efectuată.
 */
class RehabSessionRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['observations' => 'encrypted', 'evolution' => 'encrypted', 'performed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Ședințele efectuate nu se șterg; serviciul se corectează din „Servicii efectuate”.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RehabPlanItem::class, 'rehab_plan_item_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'therapist_staff_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function counts(): bool
    {
        return $this->serviceRecord?->status === ServiceRecord::STATUS_DONE;
    }
}
