<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAuditRun extends Model
{
    public const STATUSES = ['in_curs' => 'În curs', 'finalizat' => 'Finalizat', 'esuat' => 'Eșuat'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['site_checks' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(SeoAuditPage::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function latestFinished(): ?self
    {
        return self::where('status', 'finalizat')->latest('finished_at')->first();
    }
}
