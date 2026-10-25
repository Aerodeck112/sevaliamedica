<?php

namespace App\Modules\Scheduling\Models;

use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Serviciu EFECTUAT — pivotul comun pentru Rehab, CAS, Billing și Reports.
 *
 * - Se creează explicit de personal (ServiceRecordService), niciodată automat din ora programării.
 * - Nu se șterge. O greșeală se corectează: înregistrarea devine `anulat` (cu motiv) și, opțional,
 *   o înregistrare nouă o înlocuiește (corrects_id → cea veche; corrected_by_id pe cea veche → cea nouă).
 * - payer_type: privat | cas | pachet | asigurator. Doar `cas` + `efectuat` intră în pregătirea raportării CAS.
 */
class ServiceRecord extends Model
{
    public const STATUS_DONE = 'efectuat';

    public const STATUS_CANCELLED = 'anulat';

    public const PAYER_TYPES = ['privat' => 'Privat (plată directă)', 'cas' => 'CAS (decontat de casa de asigurări)', 'pachet' => 'Pachet de ședințe', 'asigurator' => 'Alt asigurător'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['performed_at' => 'immutable_datetime', 'cancelled_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_by_id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }

    public function scopeDone(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_DONE);
    }

    public function scopePayer(Builder $q, string $payer): Builder
    {
        return $q->where('payer_type', $payer);
    }

    public function payerLabel(): string
    {
        return self::PAYER_TYPES[$this->payer_type] ?? $this->payer_type;
    }
}
