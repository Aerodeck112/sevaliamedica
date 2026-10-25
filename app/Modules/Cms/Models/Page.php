<?php

namespace App\Modules\Cms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pagină de site, articol de blog sau zonă globală (ex. subsolul).
 * Conținutul stă în versiuni; pagina indică versiunea publicată și ultima versiune salvată (ciorna).
 */
class Page extends Model
{
    public const KIND_PAGE = 'page';

    public const KIND_ARTICLE = 'article';

    public const KIND_GLOBAL = 'global';

    public const FOOTER_PATH = '__subsol';

    public const BLOG_PATH = '/blog/blog-sevalia-1';

    protected $table = 'cms_pages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'editing_until' => 'datetime',
            'imported_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->orderByDesc('number');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'published_version_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'current_version_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function editingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editing_user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_version_id');
    }

    public function scopeArticles(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_ARTICLE);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->published_version_id !== null;
    }

    /** Ciorna are modificări nepublicate. */
    public function hasUnpublishedChanges(): bool
    {
        return $this->current_version_id !== null && $this->current_version_id !== $this->published_version_id;
    }

    /** Altcineva editează acum (lease activ). */
    public function lockedByOther(?User $user): ?User
    {
        if ($this->editing_user_id && $this->editing_until?->isFuture() && $this->editing_user_id !== $user?->id) {
            return $this->editingUser;
        }

        return null;
    }

    public function publicUrl(): string
    {
        return $this->kind === self::KIND_GLOBAL ? '/' : $this->path;
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_ARTICLE => 'Articol',
            self::KIND_GLOBAL => 'Zonă globală',
            default => 'Pagină',
        };
    }
}
