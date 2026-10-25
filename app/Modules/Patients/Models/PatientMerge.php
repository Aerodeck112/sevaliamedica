<?php

namespace App\Modules\Patients\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMerge extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'source_patient_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'target_patient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
