<?php

namespace App\Modules\Cms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O versiune imuabilă a unei pagini. Fiecare salvare creează o versiune nouă; restaurarea copiază
 * o versiune veche într-una nouă, deci istoricul nu se pierde niciodată.
 *
 * blocks: listă de blocuri, fiecare {uid, type, label, snippet, hidden, html?, config?}.
 */
class PageVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cms_page_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'seo' => 'array',
            'settings' => 'array',
            'blocks' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PageVersion $version) {
            // Conținutul unei versiuni nu se modifică; doar marcajul de publicare se poate seta.
            if (array_diff(array_keys($version->getDirty()), ['published_at']) !== []) {
                throw new \LogicException('Versiunile de pagină sunt imuabile.');
            }
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function seo(string $key, mixed $default = null): mixed
    {
        return $this->seo[$key] ?? $default;
    }
}
