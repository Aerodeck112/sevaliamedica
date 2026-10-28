<?php

namespace Tests\Feature\Updates;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** install.php se oprește, fără să scrie nimic, dacă arhiva a fost dezarhivată în locul greșit. */
class InstallerLocationTest extends TestCase
{
    private string $root;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/sevalia-instalator-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    private function serve(string $docroot, string $path): string
    {
        $port = random_int(20000, 40000);
        $this->server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $docroot]);
        $this->server->start();
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }

        return (string) Http::timeout(10)->get('http://127.0.0.1:'.$port.$path)->body();
    }

    private function installer(string $dir): void
    {
        mkdir($dir, 0755, true);
        copy(base_path('deploy/public_html/install.php'), $dir.'/install.php');
    }

    public function test_extracted_inside_public_html_is_refused_without_creating_files(): void
    {
        // /home/<cont>/public_html/{public_html/install.php, sevalia/releases/...}
        $this->installer($this->root.'/public_html/public_html');
        mkdir($this->root.'/public_html/sevalia/releases/0.9.1', 0755, true);

        $html = $this->serve($this->root.'/public_html', '/public_html/install.php');
        $this->assertStringContainsString('Arhiva a fost dezarhivată în locul greșit', $html);
        $this->assertStringContainsString('Up One Level', $html);
        $this->assertDirectoryDoesNotExist($this->root.'/public_html/sevalia/shared', 'nu se creează nimic în zona publică');
    }

    public function test_missing_sevalia_directory_is_explained(): void
    {
        $this->installer($this->root.'/public_html');
        $html = $this->serve($this->root.'/public_html', '/install.php');
        $this->assertStringContainsString('Lipsește directorul „sevalia”', $html);
    }

    public function test_correct_layout_passes_the_location_check(): void
    {
        $this->installer($this->root.'/public_html');
        mkdir($this->root.'/sevalia/releases/0.9.1', 0755, true);
        $html = $this->serve($this->root.'/public_html', '/install.php');
        $this->assertStringNotContainsString('locul greșit', $html);
        $this->assertStringNotContainsString('Lipsește directorul', $html);
        // Trece de verificarea locației; directorul de versiune de test e gol, deci urmează mesajul despre pachet.
        $this->assertStringContainsString('Pachetul nu a fost găsit', $html);
    }
}
