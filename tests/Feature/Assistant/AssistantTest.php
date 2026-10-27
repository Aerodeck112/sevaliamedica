<?php

namespace Tests\Feature\Assistant;

use App\Core\Settings\Settings;
use App\Modules\Assistant\Services\Assistant;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageService;
use App\Modules\Site\Services\PageCache;
use Database\Seeders\ClinicPublicDataSeeder;
use Tests\TestCase;

/** Asistentul Sevalia: răspunde doar din conținutul public, nu inventează tarife și trimite urgențele la 112. */
class AssistantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicPublicDataSeeder::class);
    }

    private function publish(string $path, string $title, string $html, array $seo = []): void
    {
        $page = Page::create(['path' => $path, 'title' => $title, 'kind' => Page::KIND_PAGE]);
        $service = app(PageService::class);
        $version = $service->saveVersion($page, [['uid' => 'b1', 'type' => 'html', 'label' => 'Text', 'hidden' => false, 'html' => $html]], $seo, [], null, null);
        $service->publish($page->fresh(), $version, null);
    }

    private function ask(string $q): array
    {
        return $this->postJson('/asistent/intrebare', ['q' => $q])->assertOk()->json();
    }

    public function test_practical_answers_come_from_clinic_data(): void
    {
        $hours = $this->ask('Care este programul?');
        $this->assertStringContainsString('Luni–Joi 07:00–22:00', $hours['answer']);
        $this->assertStringContainsString('Sâmbătă–Duminică închis', $hours['answer']);

        $this->assertStringContainsString('str. Constandin Hagi Stoian', $this->ask('Unde vă aflați?')['answer']);
        $this->assertStringContainsString('dr. Orban Emese', $this->ask('Aveți neurolog?')['answer']);

        $price = $this->ask('Cât costă un masaj?');
        $this->assertStringContainsString('recepția vă confirmă prețul', $price['answer']);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(lei|ron)/i', $price['answer'], 'nu inventează tarife');

        $cas = $this->ask('Decontați prin CAS?');
        $this->assertStringContainsString('recepția vă spune exact', $cas['answer']);
    }

    public function test_content_answers_cite_the_source_page_and_follow_publishing(): void
    {
        $this->publish('/kinetoterapie', 'Kinetoterapie', '<section><h1>KINETOTERAPIE</h1><p>Text demonstrativ despre exerciții de recuperare pentru pacienți adulți și copii.</p>'
            .'<div class="accordion"><div class="card"><div class="card-header">Care sunt indicațiile kinetoterapiei la copii?</div>'
            .'<div class="card-body"><ul><li>Tulburări de dezvoltare motorie (text demonstrativ).</li><li>Probleme ortopedice.</li></ul></div></div></div></section>',
            ['service_name' => 'Kinetoterapie']);

        $r = $this->ask('Faceți kinetoterapie la copii?');
        $this->assertSame('continut', $r['kind']);
        $this->assertStringContainsString('Care sunt indicațiile kinetoterapiei la copii?', $r['answer']);
        $this->assertStringContainsString('motorie (text demonstrativ). Probleme', $r['answer'], 'textul din liste nu se lipește');
        $this->assertSame('/kinetoterapie', $r['source']);

        // O pagină publicată ulterior intră în răspunsuri fără nicio altă acțiune.
        $this->assertSame('necunoscut', $this->ask('Ce este salinoterapia?')['kind']);
        $this->publish('/salinoterapie', 'Salinoterapie', '<section><h1>SALINOTERAPIE</h1><p>Salinoterapia folosește aerosoli salini într-o cameră special amenajată (text demonstrativ).</p></section>');
        $this->assertSame('/salinoterapie', $this->ask('Ce este salinoterapia?')['source']);
    }

    public function test_emergencies_and_medical_questions(): void
    {
        foreach (['Am o durere puternică în piept', 'mă doare inima', 'nu pot respira', 'a leșinat cineva', 'cred că face AVC'] as $q) {
            $r = $this->ask($q);
            $this->assertSame('urgenta', $r['kind'], $q);
            $this->assertStringContainsString('112', $r['answer']);
        }
        $this->assertFalse(Assistant::isEmergency('ma doare spatele de o saptamana'));
    }

    public function test_endpoint_privacy_limits_and_widget(): void
    {
        $this->publish('/masaj', 'Masaj', '<section><h1>MASAJ</h1><p>Text demonstrativ suficient de lung despre masajul terapeutic, pentru căutare.</p></section>');
        $response = $this->postJson('/asistent/intrebare', ['q' => 'Care este programul?']);
        $this->assertEmpty($response->headers->getCookies(), 'fără cookie de sesiune');
        $this->postJson('/asistent/intrebare', ['q' => str_repeat('a', 301)])->assertStatus(422);
        $this->postJson('/asistent/intrebare', ['q' => 'program'], ['Origin' => 'https://altsite.example'])->assertForbidden();
        $this->get('/asistent/intrebare?q=program')->assertNotFound(); // întrebările nu se acceptă în URL

        $this->assertStringContainsString('data-assistant', (string) $this->get('/masaj')->getContent());
        app(Settings::class)->set('site.assistant', false);
        PageCache::flush();
        $this->assertStringNotContainsString('data-assistant', (string) $this->get('/masaj')->getContent());
        $this->postJson('/asistent/intrebare', ['q' => 'program'])->assertNotFound();
    }
}
