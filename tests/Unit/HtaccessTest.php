<?php

namespace Tests\Unit;

use App\Core\Support\Htaccess;
use PHPUnit\Framework\TestCase;

/** public_html/.htaccess: regulile SEVALIA se scriu, blocurile cPanel și regulile proprii se păstrează. */
class HtaccessTest extends TestCase
{
    private string $dir;

    private const CPANEL = "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php83 .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit\n";

    private const INI = "# BEGIN cPanel-generated php ini directives, do not edit\n<IfModule php8_module>\n   php_value upload_max_filesize 256M\n</IfModule>\n# END cPanel-generated php ini directives, do not edit\n";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/htaccess-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{,.}*', GLOB_BRACE) ? array_filter(glob($this->dir.'/{,.}*', GLOB_BRACE), 'is_file') : []);
        @rmdir($this->dir);
    }

    private function template(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/deploy/public_html/.htaccess');
    }

    public function test_existing_cpanel_file_gets_sevalia_rules_and_keeps_php_version(): void
    {
        file_put_contents($this->dir.'/.htaccess', "\n".self::INI."\n".self::CPANEL);
        $this->assertTrue(Htaccess::write($this->dir, $this->template()));

        $written = (string) file_get_contents($this->dir.'/.htaccess');
        $this->assertStringStartsWith('# SEVALIA-MANAGED', $written);
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $written);
        $this->assertStringContainsString('AddHandler application/x-httpd-ea-php83', $written);
        $this->assertStringContainsString('php_value upload_max_filesize 256M', $written);
        $this->assertCount(1, glob($this->dir.'/.htaccess.inainte-de-sevalia-*'), 'fișierul vechi e salvat');
        $this->assertTrue(Htaccess::isManaged($this->dir));

        // A doua scriere (cron, actualizare) nu mai schimbă nimic.
        $this->assertFalse(Htaccess::write($this->dir, $this->template()));
        $this->assertCount(1, glob($this->dir.'/.htaccess.inainte-de-sevalia-*'));
    }

    public function test_custom_rules_are_kept_first_and_template_updates_apply(): void
    {
        $custom = "# BEGIN reguli proprii\nRedirect 301 /vechi /nou\n# END reguli proprii\n";
        $old = str_replace("# BEGIN reguli proprii\n# (reguli proprii ale clinicii, ex. redirecționări; rulează înaintea regulilor SEVALIA)\n# END reguli proprii\n", $custom, $this->template());
        file_put_contents($this->dir.'/.htaccess', str_replace('DirectoryIndex index.php', 'DirectoryIndex index.php index.html', $old).self::CPANEL);

        $this->assertTrue(Htaccess::write($this->dir, $this->template()));
        $written = (string) file_get_contents($this->dir.'/.htaccess');
        $this->assertStringContainsString('Redirect 301 /vechi /nou', $written);
        $this->assertLessThan(strpos($written, 'RewriteRule ^ index.php'), strpos($written, 'Redirect 301 /vechi /nou'), 'regulile proprii rulează primele');
        $this->assertStringNotContainsString('index.html', $written, 'restul fișierului urmează șablonul versiunii');
        $this->assertStringContainsString('ea-php83', $written);
        $this->assertSame([], glob($this->dir.'/.htaccess.inainte-de-sevalia-*'), 'fișierul era deja al SEVALIA: fără copie');
    }

    public function test_missing_file_is_created(): void
    {
        $this->assertTrue(Htaccess::write($this->dir, $this->template()));
        $this->assertStringContainsString('RewriteEngine On', (string) file_get_contents($this->dir.'/.htaccess'));
    }
}
