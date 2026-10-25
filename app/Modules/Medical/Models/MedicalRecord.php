<?php

namespace App\Modules\Medical\Models;

use App\Models\User;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Fișă / document medical redactat în aplicație.
 *
 * - draft: editabil de autor (blocare optimistă prin `version`);
 * - finalizat: conținut IMUABIL, cu sumă de control; finalizarea cere reconfirmarea identității utilizatorului.
 *   Aceasta NU este o semnătură electronică calificată.
 * - abandonat: draft la care s-a renunțat (cu motiv) — nu se șterge.
 *
 * Corecturile după finalizare sunt înregistrări noi (kind = completare | rectificare) cu amends_id → fișa
 * originală, motiv, autor și dată. Fișa originală rămâne neatinsă și vizibilă în istoric.
 */
class MedicalRecord extends Model
{
    public const DRAFT = 'draft';

    public const FINAL = 'finalizat';

    public const ABANDONED = 'abandonat';

    public const KINDS = [
        'consultatie' => 'Consultație',
        'reevaluare' => 'Reevaluare',
        'nota' => 'Notă clinică',
        'completare' => 'Completare',
        'rectificare' => 'Rectificare',
    ];

    public const STATUS_LABELS = [self::DRAFT => 'Draft', self::FINAL => 'Finalizată', self::ABANDONED => 'Abandonată'];

    /** Coloane care se pot modifica după finalizare (doar metadate tehnice, niciodată conținut). */
    private const MUTABLE_AFTER_FINAL = ['updated_at'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'encrypted:array',
            'amendment_reason' => 'encrypted',
            'finalized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (MedicalRecord $record) {
            if ($record->getOriginal('status') === self::FINAL) {
                $changed = array_diff(array_keys($record->getDirty()), self::MUTABLE_AFTER_FINAL);
                if ($changed) {
                    throw new LogicException('O fișă finalizată nu poate fi modificată. Folosiți „Completare” sau „Rectificare”.');
                }
            }
            if ($record->getOriginal('status') === self::ABANDONED) {
                throw new LogicException('Un draft abandonat nu mai poate fi modificat.');
            }
        });
        static::deleting(fn () => throw new LogicException('Fișele medicale nu se șterg.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(MedicalTemplateVersion::class, 'template_version_id');
    }

    public function amends(): BelongsTo
    {
        return $this->belongsTo(self::class, 'amends_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(self::class, 'amends_id')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MedicalDocument::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOriginals(Builder $q): Builder
    {
        return $q->whereNull('amends_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isFinal(): bool
    {
        return $this->status === self::FINAL;
    }

    public function isAmendment(): bool
    {
        return $this->amends_id !== null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function statusTone(): ?string
    {
        return match ($this->status) {
            self::FINAL => 'ok',
            self::DRAFT => 'warn',
            default => null,
        };
    }

    public function schema(): array
    {
        return $this->templateVersion?->schema ?? self::freeTextSchema();
    }

    /** Structura folosită fără șablon (note clinice, completări): un singur câmp de text liber. */
    public static function freeTextSchema(): array
    {
        return ['sections' => [['key' => 's1', 'title' => 'Conținut', 'fields' => [
            ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'required' => true, 'help' => null],
        ]]]];
    }

    /** Fișa originală (rădăcina lanțului de completări / rectificări). */
    public function root(): self
    {
        return $this->amends_id ? ($this->amends ?? $this) : $this;
    }

    /** Ultima rectificare finalizată a acestei fișe originale, dacă există. */
    public function latestRectification(): ?self
    {
        return $this->amendments->where('status', self::FINAL)->where('kind', 'rectificare')->sortByDesc('finalized_at')->first();
    }

    /** Suma de control a conținutului (valori + metadatele clinice), calculată la finalizare. */
    public function computeHash(): string
    {
        return hash('sha256', json_encode([
            'patient' => (int) $this->patient_id,
            'staff' => (int) $this->staff_member_id,
            'template_version' => $this->template_version_id ? (int) $this->template_version_id : null,
            'kind' => $this->kind,
            'title' => $this->title,
            'content' => $this->content,
            'amends' => $this->amends_id ? (int) $this->amends_id : null,
            'reason' => $this->amendment_reason,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function integrityOk(): bool
    {
        return ! $this->isFinal() || hash_equals((string) $this->content_hash, $this->computeHash());
    }
}
