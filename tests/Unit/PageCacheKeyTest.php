<?php

namespace Tests\Unit;

use App\Modules\Site\Services\PageCache;
use Tests\TestCase;

class PageCacheKeyTest extends TestCase
{
    /** După o actualizare, paginile din cache nu mai trimit stilurile și scripturile versiunii vechi. */
    public function test_cache_key_changes_with_application_version(): void
    {
        config(['sevalia.version' => '0.9.4']);
        $before = PageCache::key('/');
        config(['sevalia.version' => '0.9.5']);

        $this->assertNotSame($before, PageCache::key('/'));
        $this->assertSame(PageCache::key('/'), PageCache::key('/'));
    }
}
