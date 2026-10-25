<?php

namespace App\Modules\Medical\Models;

use App\Models\User;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Avertizare introdusă de un clinician (ex. alergie, contraindicație). Text liber, fără reguli automate.
 * Se încheie cu motiv; nu se șterge.
 */
class ClinicalAlert extends Model
{
    public const SEVERITIES = ['info' => 'Informare', 'atentie' => 'Atenție', 'critic' => 'Critic'];

    public const TONES = ['info' => 'info', 'atentie' => 'warn', 'critic' => 'danger'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['text' => 'encrypted', 'ended_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Avertizările nu se șterg; se pot încheia cu motiv.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('ended_at');
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? $this->severity;
    }

    public function tone(): string
    {
        return self::TONES[$this->severity] ?? 'warn';
    }
}
