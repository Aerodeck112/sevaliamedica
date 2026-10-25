<?php

namespace App\Modules\Cms\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Site\Services\PageCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operațiile asupra conținutului paginilor: versiuni noi (ciornă), publicare, restaurare, blocare la editare.
 * Fiecare salvare creează o versiune nouă; nicio versiune nu se modifică sau se șterge.
 */
class PageService
{
    public const LEASE_MINUTES = 5;

    /**
     * Salvează o versiune nouă dacă pagina nu a fost modificată între timp (blocare optimistă).
     *
     * @throws EditConflict dacă $expectedLockVersion nu mai corespunde
     */
    public function saveVersion(Page $page, array $blocks, array $seo, array $settings, ?User $user, ?int $expectedLockVersion, ?string $note = null, ?string $title = null): PageVersion
    {
        return DB::transaction(function () use ($page, $blocks, $seo, $settings, $user, $expectedLockVersion, $note, $title) {
            /** @var Page $locked */
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            if ($expectedLockVersion !== null && $locked->lock_version !== $expectedLockVersion) {
                throw new EditConflict($locked);
            }
            $number = (int) PageVersion::where('page_id', $locked->id)->max('number') + 1;
            $version = PageVersion::create([
                'page_id' => $locked->id,
                'number' => $number,
                'title' => $title ?? $locked->title,
                'seo' => $seo,
                'settings' => $settings,
                'blocks' => array_values($blocks),
                'note' => $note,
                'created_by' => $user?->id,
                'created_at' => now(),
            ]);
            $locked->forceFill([
                'current_version_id' => $version->id,
                'lock_version' => $locked->lock_version + 1,
                'title' => $title ?? $locked->title,
                'updated_by' => $user?->id,
            ])->save();
            $page->setRawAttributes($locked->getAttributes(), true);
            Audit::log('cms.page.saved', $locked, ['versiune' => $number, 'nota' => $note]);

            return $version;
        });
    }

    public function publish(Page $page, PageVersion $version, ?User $user): void
    {
        if ($version->page_id !== $page->id) {
            throw new RuntimeException('Versiunea nu aparține paginii.');
        }
        DB::transaction(function () use ($page, $version) {
            $version->forceFill(['published_at' => now()])->save();
            $page->forceFill([
                'published_version_id' => $version->id,
                'status' => 'published',
                'published_at' => $page->kind === Page::KIND_ARTICLE ? ($page->published_at ?? now()) : $page->published_at,
            ])->save();
        });
        Audit::log('cms.page.published', $page, ['versiune' => $version->number]);
        PageCache::flush();
    }

    public function unpublish(Page $page): void
    {
        $page->forceFill(['status' => 'draft'])->save();
        Audit::log('cms.page.unpublished', $page);
        PageCache::flush();
    }

    /** Restaurarea creează o versiune nouă identică cu cea veche (istoricul rămâne complet). */
    public function restore(Page $page, PageVersion $old, ?User $user, ?int $expectedLockVersion): PageVersion
    {
        $version = $this->saveVersion($page, $old->blocks, $old->seo ?? [], $old->settings ?? [], $user, $expectedLockVersion, 'Restaurat din versiunea '.$old->number, $old->title);
        Audit::log('cms.page.restored', $page, ['din_versiunea' => $old->number, 'versiune_noua' => $version->number]);

        return $version;
    }

    /** Preia „lease”-ul de editare. Întoarce utilizatorul care deține deja lease-ul, dacă există. */
    public function acquireLease(Page $page, User $user): ?User
    {
        return DB::transaction(function () use ($page, $user) {
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            if ($other = $locked->lockedByOther($user)) {
                return $other;
            }
            $locked->forceFill(['editing_user_id' => $user->id, 'editing_until' => now()->addMinutes(self::LEASE_MINUTES)])->save();

            return null;
        });
    }

    public function releaseLease(Page $page, User $user): void
    {
        Page::whereKey($page->id)->where('editing_user_id', $user->id)->update(['editing_user_id' => null, 'editing_until' => null]);
    }
}
