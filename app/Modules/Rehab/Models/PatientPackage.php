<?php

namespace App\Modules\Rehab\Models;

use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pachet atribuit unui pacient; consumul = ședințele efectuate (cu serviciul efectuat valabil) legate de pachet. */
class PatientPackage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(PackageDefinition::class, 'rehab_package_definition_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(RehabSessionRecord::class);
    }

    public function used(): int
    {
        return $this->sessions()->whereHas('serviceRecord', fn ($q) => $q->where('status', ServiceRecord::STATUS_DONE))->count();
    }

    public function remaining(): int
    {
        return max(0, $this->sessions_total - $this->used());
    }

    /** Motivul pentru care pachetul nu poate fi consumat la data dată, sau null. */
    public function blockReason(\DateTimeInterface $at, int $serviceId): ?string
    {
        if ($this->status !== 'activ') {
            return 'Pachetul nu este activ.';
        }
        if ($at < $this->valid_from->startOfDay() || ($this->valid_until && $at > $this->valid_until->endOfDay())) {
            return 'Ședința este în afara perioadei de valabilitate a pachetului.';
        }
        if (! in_array($serviceId, array_map('intval', $this->definition->service_ids ?? []), true)) {
            return 'Serviciul nu este inclus în pachet.';
        }
        if ($this->remaining() <= 0) {
            return 'Pachetul nu mai are ședințe disponibile.';
        }

        return null;
    }

    public function label(): string
    {
        return $this->definition->name.' ('.$this->used().'/'.$this->sessions_total.')';
    }
}
