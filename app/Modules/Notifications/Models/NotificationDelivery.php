<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O livrare = un mesaj către un destinatar pe un canal. Cheia de idempotență (unică) garantează că același
 * eveniment nu produce două livrări, oricâte evenimente duplicate sau rulări de cron ar exista.
 */
class NotificationDelivery extends Model
{
    public const PENDING = 'in_asteptare';

    public const SENDING = 'in_trimitere';

    public const SENT = 'trimis';

    public const SIMULATED = 'simulat';

    public const FAILED = 'esuat';

    public const FAILED_FINAL = 'esuat_definitiv';

    public const CANCELLED = 'anulat';

    public const UNKNOWN = 'necunoscut';

    public const LABELS = [
        self::PENDING => 'În așteptare',
        self::SENDING => 'În trimitere',
        self::SENT => 'Trimis',
        self::SIMULATED => 'Simulat (nu s-a trimis)',
        self::FAILED => 'Eșuat — se reîncearcă',
        self::FAILED_FINAL => 'Eșuat definitiv',
        self::CANCELLED => 'Anulat',
        self::UNKNOWN => 'Rezultat necunoscut',
    ];

    public const TONES = [
        self::PENDING => 'info', self::SENDING => 'info', self::SENT => 'ok', self::SIMULATED => 'accent',
        self::FAILED => 'warn', self::FAILED_FINAL => 'danger', self::CANCELLED => null, self::UNKNOWN => 'warn',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'payload'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'payload' => 'encrypted:array',
            'scheduled_for' => 'datetime',
            'next_attempt_at' => 'datetime',
            'locked_until' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function statusTone(): ?string
    {
        return self::TONES[$this->status] ?? null;
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [self::FAILED, self::FAILED_FINAL, self::UNKNOWN], true);
    }
}
