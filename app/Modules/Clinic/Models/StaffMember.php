<?php

namespace App\Modules\Clinic\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Personalul clinicii (medici, terapeuți, asistenți). Poate avea cont de utilizator (user_id) și poate apărea
 * pe site (show_on_site). Numele afișat se păstrează exact cum e publicat.
 */
class StaffMember extends Model
{
    public const KINDS = [
        'medic' => 'Medic',
        'terapeut' => 'Terapeut / fizioterapeut',
        'asistent' => 'Asistent medical',
        'altul' => 'Alt personal',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'show_on_site' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('last_name')->orderBy('first_name');
    }

    public function scopeOnSite(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('show_on_site', true)->orderBy('site_sort');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
