<?php

namespace App\Modules\Medical\Models;

use App\Modules\Clinic\Models\Specialty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Șablon de fișă configurat de clinică. „draft_schema” se editează liber; fișele noi folosesc ultima versiune
 * publicată, iar o fișă păstrează versiunea cu care a fost creată.
 */
class MedicalTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['draft_schema' => 'array', 'is_active' => 'boolean', 'is_example' => 'boolean'];
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MedicalTemplateVersion::class)->orderByDesc('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(MedicalTemplateVersion::class)->latestOfMany('version');
    }

    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('is_active', true)->whereHas('versions');
    }

    /** Draftul diferă de ultima versiune publicată. */
    public function hasUnpublishedChanges(): bool
    {
        $latest = $this->latestVersion;

        return ! $latest || json_encode($latest->schema) !== json_encode($this->draft_schema);
    }
}
