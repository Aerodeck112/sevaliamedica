<?php

namespace App\Modules\Rehab\Models;

use Illuminate\Database\Eloquent\Model;

/** Tip de pachet de ședințe definit de clinică (structură, fără preț în acest modul). */
class PackageDefinition extends Model
{
    protected $table = 'rehab_package_definitions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['service_ids' => 'array', 'is_active' => 'boolean'];
    }
}
