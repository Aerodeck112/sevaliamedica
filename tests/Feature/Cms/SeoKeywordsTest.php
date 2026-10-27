<?php

namespace Tests\Feature\Cms;

use Tests\TestCase;

/** Paginile pentru cuvintele cheie cerute de clinică au titlul, descrierea și datele structurate recomandate. */
class SeoKeywordsTest extends TestCase
{
    use ImportsSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importSite();
    }

    public function test_keyword_pages_have_recommended_title_description_and_structured_data(): void
    {
        $recommended = json_decode((string) file_get_contents(base_path('database/content/seo-recomandat.json')), true);
        foreach ($recommended as $path => $seo) {
            if (str_starts_with($path, '_')) {
                continue;
            }
            $html = (string) $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('<title>'.e($seo['title']).'</title>', $html, $path);
            if (! empty($seo['description'])) {
                $this->assertStringContainsString('content="'.e($seo['description']).'"', $html, $path);
                $this->assertLessThanOrEqual(160, mb_strlen($seo['description']), $path);
            }
            $this->assertLessThanOrEqual(65, mb_strlen($seo['title']), $path);
            $this->assertMatchesRegularExpression('/"@type":"Service".*"Târgu Mureș"/u', $html, $path);
            $this->assertStringContainsString('"@type":"BreadcrumbList"', $html, $path);
        }
    }

    public function test_faq_structured_data_matches_visible_questions(): void
    {
        $html = (string) $this->get('/kinetoterapie')->getContent();
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('Care sunt indicațiile kinetoterapiei la copii?', $html);
        preg_match('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $m);
        $graph = json_decode($m[1], true)['@graph'];
        $faq = collect($graph)->firstWhere('@type', 'FAQPage');
        foreach ($faq['mainEntity'] as $q) {
            $this->assertStringContainsString(e($q['name']), $html);
        }
    }

    public function test_visible_page_text_is_unchanged(): void
    {
        // Textul vizibil rămâne cel original: titlul paginii din conținut nu se schimbă.
        $this->get('/kinetoterapie')->assertSee('KINETOTERAPIE')->assertSee('Obiectivele kinetoterapiei');
        $this->get('/masaj')->assertSee('MASAJ');
    }
}
