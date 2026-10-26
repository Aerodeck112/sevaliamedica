<?php

namespace App\Modules\Medical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Model de consimțământ. Textul îl furnizează clinica; fiecare modificare publică o versiune nouă. */
class ConsentTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ConsentTemplateVersion::class)->orderByDesc('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ConsentTemplateVersion::class)->latestOfMany('version');
    }

    /** Un model fără text publicat nu poate fi folosit („de completat de clinică”). */
    public function isReady(): bool
    {
        return $this->latestVersion !== null && trim((string) $this->latestVersion->body) !== '';
    }
}
