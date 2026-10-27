<?php

namespace App\Modules\Kiosk\Models;

use App\Modules\Clinic\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskDevice extends Model
{
    public const MODES = ['consimtamant' => 'Consimțăminte', 'recenzie' => 'Recenzii', 'ambele' => 'Consimțăminte și recenzii'];

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'pairing_code_hash'];

    protected function casts(): array
    {
        return ['pairing_expires_at' => 'datetime', 'paired_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->paired_at !== null;
    }

    public function accepts(string $kind): bool
    {
        return $this->mode === 'ambele' || $this->mode === $kind;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revocată',
            $this->paired_at === null => 'neasociată',
            $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2)) => 'conectată',
            default => 'asociată',
        };
    }
}
