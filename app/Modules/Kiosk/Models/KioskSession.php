<?php

namespace App\Modules\Kiosk\Models;

use App\Models\User;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Medical\Models\PatientConsent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskSession extends Model
{
    public const STATUSES = [
        'in_asteptare' => 'În așteptare', 'deschis' => 'Deschisă pe tabletă', 'finalizat' => 'Finalizată',
        'refuzat' => 'Refuzată de pacient', 'expirat' => 'Expirată', 'anulat' => 'Anulată', 'blocat' => 'Blocată (coduri greșite)',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'opened_at' => 'datetime', 'completed_at' => 'datetime', 'auto_open' => 'boolean'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function consentVersion(): BelongsTo
    {
        return $this->belongsTo(ConsentTemplateVersion::class, 'consent_template_version_id');
    }

    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class, 'kiosk_device_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(PatientConsent::class, 'patient_consent_id');
    }

    public function isOpenable(): bool
    {
        return in_array($this->status, ['in_asteptare', 'deschis'], true) && $this->expires_at->isFuture();
    }

    public function statusLabel(): string
    {
        if ($this->status === 'in_asteptare' && $this->expires_at->isPast()) {
            return self::STATUSES['expirat'];
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Numele afișat pe tabletă: prenumele și inițiala numelui, pentru confirmarea identității fără expunere. */
    public function patientShortName(): string
    {
        $p = $this->patient;

        return trim($p->first_name.' '.mb_substr((string) $p->last_name, 0, 1).'.');
    }
}
