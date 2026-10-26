<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitare de feedback după o programare finalizată. Tokenul se păstrează doar ca hash. */
class FeedbackRequest extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'comment'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'submitted_at' => 'datetime', 'comment' => 'encrypted'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isOpen(): bool
    {
        return $this->submitted_at === null && $this->expires_at->isFuture();
    }
}
