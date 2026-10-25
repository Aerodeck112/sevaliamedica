<?php

namespace App\Modules\Patients\Models;

use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relație explicită de îngrijire între o persoană din personal și un pacient (ex. plan de recuperare, trimitere). */
class CareRelation extends Model
{
    public const SOURCES = ['manual' => 'Desemnare manuală', 'plan' => 'Plan de tratament', 'trimitere' => 'Trimitere internă'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today));
    }
}
