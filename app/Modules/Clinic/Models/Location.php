<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opening_hours' => 'array', 'is_active' => 'boolean'];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(StaffMember::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    /** Intervalele de funcționare pentru o zi (1 = luni ... 7 = duminică). @return list<array{0:string,1:string}> */
    public function hoursFor(int $isoWeekday): array
    {
        return $this->opening_hours[(string) $isoWeekday] ?? [];
    }
}
