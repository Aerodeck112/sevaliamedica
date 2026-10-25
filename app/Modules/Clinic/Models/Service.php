<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogul serviciilor clinicii: durata, resursele necesare și tariful privat.
 * Un tarif null înseamnă „neconfirmat” — nu se afișează și nu se folosește în calcule.
 */
class Service extends Model
{
    public const KINDS = [
        'consultatie' => 'Consultație',
        'procedura' => 'Procedură',
        'investigatie' => 'Investigație',
        'sedinta' => 'Ședință de recuperare',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cas_reportable' => 'boolean',
            'online_booking' => 'boolean',
            'online_auto_confirm' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ServiceRequirement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
