<?php

namespace App\Modules\Kiosk\Models;

use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['comment' => 'encrypted', 'publish_consent' => 'boolean', 'published' => 'boolean', 'moderated_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Semnătura publică a recenziei: prenumele și inițiala, fără alte date. */
    public function publicAuthor(): string
    {
        return trim(($this->patient?->first_name ?? 'Pacient').' '.mb_substr((string) $this->patient?->last_name, 0, 1).'.');
    }
}
