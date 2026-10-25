<?php

namespace App\Core\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Jurnalul de audit nu poate fi modificat.'));
        static::deleting(fn () => throw new LogicException('Jurnalul de audit nu poate fi șters din aplicație.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
