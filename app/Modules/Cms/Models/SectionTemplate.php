<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/** Șablon de secțiune, derivat dintr-o secțiune existentă a siteului; poate fi adăugat în orice pagină. */
class SectionTemplate extends Model
{
    protected $table = 'cms_section_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['block' => 'array'];
    }
}
