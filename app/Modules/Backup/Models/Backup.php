<?php

namespace App\Modules\Backup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    public const TYPES = ['database' => 'Bază de date', 'documents' => 'Documente'];

    public const STATUSES = [
        'running' => 'În curs',
        'completed' => 'Finalizat (neverificat)',
        'verified' => 'Verificat',
        'failed' => 'Eșuat',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['completed', 'verified'], true);
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'verified' => 'ok',
            'completed' => 'info',
            'failed' => 'danger',
            default => 'warn',
        };
    }
}
