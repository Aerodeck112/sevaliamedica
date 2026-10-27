<?php

namespace App\Modules\Medical\Models;

use App\Models\User;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Consimțământul unui pacient pentru o versiune exactă a textului. Retragerea se consemnează, nu se șterge. */
class PatientConsent extends Model
{
    public const METHODS = [
        'hartie_scanat' => 'Semnat pe hârtie (formular scanat)',
        'verbal_consemnat' => 'Acord verbal consemnat de personal',
        // Semnătură electronică simplă (desenată pe tableta clinicii), NU semnătură electronică calificată.
        'semnat_tableta' => 'Semnat pe tableta clinicii (semnătură electronică simplă)',
    ];

    public const GIVEN_BY = ['pacient' => 'Pacientul', 'reprezentant' => 'Reprezentantul legal'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['given_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Consimțămintele nu se șterg; se pot retrage.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ConsentTemplateVersion::class, 'consent_template_version_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MedicalDocument::class, 'medical_document_id');
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }
}
