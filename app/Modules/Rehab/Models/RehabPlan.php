<?php

namespace App\Modules\Rehab\Models;

use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Medical\Models\MedicalRecord;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Plan de recuperare: consultație → plan aprobat de clinician → serii de ședințe → efectuare → reevaluare → închidere.
 * Obiectivele, observațiile și concluzia sunt text liber scris de clinician (criptat); aplicația nu propune tratamente.
 */
class RehabPlan extends Model
{
    public const DRAFT = 'draft';

    public const APPROVED = 'aprobat';

    public const ACTIVE = 'in_desfasurare';

    public const SUSPENDED = 'suspendat';

    public const CLOSED = 'incheiat';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::APPROVED => 'Aprobat',
        self::ACTIVE => 'În desfășurare',
        self::SUSPENDED => 'Suspendat',
        self::CLOSED => 'Încheiat',
    ];

    public const TONES = [self::DRAFT => 'warn', self::APPROVED => 'info', self::ACTIVE => 'accent', self::SUSPENDED => 'danger', self::CLOSED => 'ok'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'objectives' => 'encrypted',
            'notes' => 'encrypted',
            'closing_conclusion' => 'encrypted',
            'planned_start' => 'date',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Planurile de recuperare nu se șterg.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function physician(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'physician_staff_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RehabPlanItem::class)->orderBy('sort')->orderBy('id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PatientPackage::class, 'patient_package_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reevaluations(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'rehab_plan_id')->orderByDesc('created_at');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [self::APPROVED, self::ACTIVE, self::SUSPENDED]);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusTone(): ?string
    {
        return self::TONES[$this->status] ?? null;
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function canSchedule(): bool
    {
        return in_array($this->status, [self::APPROVED, self::ACTIVE], true);
    }
}
