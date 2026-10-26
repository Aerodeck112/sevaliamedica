<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Element de meniu. Tipuri: link (simplu), dropdown (listă de subelemente), mega (submeniu extins al cărui
 * conținut este un bloc editabil, identic cu meniurile extinse ale siteului original).
 */
class MenuItem extends Model
{
    public const KINDS = ['link' => 'Link simplu', 'dropdown' => 'Listă derulantă', 'mega' => 'Meniu extins'];

    protected $table = 'cms_menu_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mega_block' => 'array', 'new_tab' => 'boolean', 'is_visible' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }
}
