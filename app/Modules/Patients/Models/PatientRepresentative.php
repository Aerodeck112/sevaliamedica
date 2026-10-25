<?php

namespace App\Modules\Patients\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientRepresentative extends Model
{
    public const RELATIONSHIPS = [
        'parinte' => 'Părinte',
        'tutore' => 'Tutore',
        'curator' => 'Curator',
        'imputernicit' => 'Împuternicit',
        'altul' => 'Altă calitate',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['document_number'];

    protected function casts(): array
    {
        return ['document_number' => 'encrypted', 'valid_from' => 'date', 'valid_until' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function relationshipLabel(): string
    {
        return self::RELATIONSHIPS[$this->relationship] ?? $this->relationship;
    }

    public function isValid(): bool
    {
        return $this->valid_until === null || $this->valid_until->endOfDay()->isFuture();
    }
}
