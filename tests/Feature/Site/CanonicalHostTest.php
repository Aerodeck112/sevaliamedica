<?php

namespace Tests\Feature\Site;

use App\Modules\Site\Services\PageCache;
use Illuminate\Http\Request;
use Tests\TestCase;

class CanonicalHostTest extends TestCase
{
    public function test_bare_domain_redirects_permanently_to_www(): void
    {
        config(['app.url' => 'https://www.sevalia.ro']);

        $this->get('https://sevalia.ro/echipa-noastra?x=1')
            ->assertStatus(301)
            ->assertRedirect('https://www.sevalia.ro/echipa-noastra?x=1');
    }

    public function test_www_redirects_to_bare_domain_when_that_is_canonical(): void
    {
        config(['app.url' => 'https://sevalia.ro']);

        $this->get('https://www.sevalia.ro/')->assertStatus(301)->assertRedirect('https://sevalia.ro/');
    }

    public function test_forms_keep_their_method_on_redirect(): void
    {
        config(['app.url' => 'https://www.sevalia.ro']);

        $this->post('https://sevalia.ro/asistent/intrebare', ['q' => 'program'])->assertStatus(308);
    }

    public function test_canonical_and_unrelated_hosts_are_not_redirected(): void
    {
        config(['app.url' => 'https://www.sevalia.ro']);

        $this->get('https://www.sevalia.ro/robots.txt')->assertOk();
        $this->get('http://localhost/robots.txt')->assertOk();
    }

    public function test_page_cache_key_depends_on_host(): void
    {
        $this->app->instance('request', Request::create('https://www.sevalia.ro/'));
        $www = PageCache::key('/');
        $this->app->instance('request', Request::create('https://sevalia.ro/'));

        $this->assertNotSame($www, PageCache::key('/'));
    }
}
