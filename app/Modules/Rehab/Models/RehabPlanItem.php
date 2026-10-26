<?php

namespace App\Modules\Rehab\Models;

use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentSeries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** O procedură din plan: serviciu din catalog, număr de ședințe, frecvență, terapeut preferat. */
class RehabPlanItem extends Model
{
    protected $guarded = ['id'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RehabPlan::class, 'rehab_plan_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function preferredTherapist(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'preferred_therapist_id');
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(AppointmentSeries::class, 'rehab_item_series', 'rehab_plan_item_id', 'appointment_series_id');
    }

    public function sessionRecords(): HasMany
    {
        return $this->hasMany(RehabSessionRecord::class);
    }

    /** Toate programările din seriile legate de această procedură. */
    public function appointmentsQuery()
    {
        return Appointment::whereIn('series_id', $this->series()->pluck('appointment_series.id'));
    }

    public function payer(): string
    {
        return $this->payer_type ?: $this->plan->payer_type;
    }
}
