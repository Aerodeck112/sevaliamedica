<?php

namespace App\Modules\Scheduling\Models;

use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Support\AppointmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O programare sau o solicitare de programare.
 * Resursele rezervate (persoană, sală, aparat) sunt în appointment_resources; istoricul în appointment_events.
 * Modificările de status și de oră se fac DOAR prin BookingService (verificări + blocări + istoric).
 */
class Appointment extends Model
{
    public const PAYER_TYPES = ['privat' => 'Privat', 'cas' => 'CAS', 'pachet' => 'Pachet', 'asigurator' => 'Alt asigurător'];

    public const SOURCES = ['receptie' => 'Recepție', 'telefon' => 'Telefon', 'site' => 'Site', 'serie' => 'Serie de ședințe'];

    protected $guarded = ['id'];

    protected $hidden = ['request_contact'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'request_preferred_start' => 'immutable_datetime',
            'cancelled_at' => 'datetime',
            'reschedule_requested_at' => 'datetime',
            'request_contact' => 'encrypted:array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
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

    public function series(): BelongsTo
    {
        return $this->belongsTo(AppointmentSeries::class, 'series_id');
    }

    public function requestSpecialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'request_specialty_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(AppointmentResource::class);
    }

    public function activeResources(): HasMany
    {
        return $this->hasMany(AppointmentResource::class)->whereNull('released_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class)->orderBy('id');
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeBetween(Builder $q, $from, $to): Builder
    {
        return $q->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public function scopeVisibleInCalendar(Builder $q): Builder
    {
        return $q->whereNotNull('starts_at')->whereNotIn('status', [AppointmentStatus::SOLICITARE]);
    }

    public function statusLabel(): string
    {
        return AppointmentStatus::label($this->status);
    }

    public function statusTone(): ?string
    {
        return AppointmentStatus::tone($this->status);
    }

    /** @return list<string> */
    public function allowedTransitions(): array
    {
        return AppointmentStatus::TRANSITIONS[$this->status] ?? [];
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function durationMinutes(): int
    {
        return $this->starts_at && $this->ends_at ? (int) $this->starts_at->diffInMinutes($this->ends_at) : 0;
    }

    /** Numele afișat: pacientul identificat sau, pentru solicitări, numele din formular. */
    public function displayName(): string
    {
        return $this->patient?->fullName() ?? ($this->request_contact['name'] ?? 'Solicitare fără nume');
    }
}
