<?php

namespace Tests\Feature\Updates;

use App\Modules\Updates\Recovery\RecoveryKey;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use SevaliaRecovery;
use Tests\Concerns\UsesIsolatedDeployment;
use Tests\TestCase;

/**
 * recovery.php trebuie să funcționeze fără aplicație: testul HTTP îl servește dintr-un director care conține
 * doar acest fișier, cu un deployment fără vendor/ în versiunea activă.
 */
class RecoveryScriptTest extends TestCase
{
    use UsesIsolatedDeployment;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedDeployment();
        $this->fakeRelease('0.2.0');
        file_put_contents($this->deployRoot.'/current.json', json_encode(['release' => '0.2.0', 'previous' => '0.1.0', 'activated_at' => gmdate('c')]));
        $this->key = RecoveryKey::generate($this->deployRoot.'/shared/keys');
        if (! defined('SEVALIA_RECOVERY_NO_RUN')) {
            define('SEVALIA_RECOVERY_NO_RUN', true);
        }
        require_once base_path('deploy/public_html/recovery.php');
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDeployment();
        parent::tearDown();
    }

    public function test_key_check_with_rate_limit(): void
    {
        $r = new SevaliaRecovery($this->deployRoot);
        $this->assertTrue($r->isEnabled());
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($r->checkKey('CHEIE-GRESITA', '10.0.0.1'));
        }
        $this->assertTrue($r->isBlocked('10.0.0.1'));
        $this->assertFalse($r->checkKey($this->key, '10.0.0.1'), 'După 5 eșecuri, chiar și cheia corectă este refuzată temporar.');
        $this->assertTrue($r->checkKey(strtolower($this->key), '10.0.0.2'));
        $this->assertStringContainsString('cheie_gresita', file_get_contents($this->deployRoot.'/shared/updates/recovery.log'));
    }

    public function test_switch_release_and_maintenance(): void
    {
        $r = new SevaliaRecovery($this->deployRoot);
        $r->switchRelease('0.1.0', '127.0.0.1');
        $pointer = json_decode(file_get_contents($this->deployRoot.'/current.json'), true);
        $this->assertSame('0.1.0', $pointer['release']);
        $this->assertSame('0.2.0', $pointer['previous']);

        try {
            $r->switchRelease('../../etc', '127.0.0.1');
            $this->fail('Trebuia refuzat.');
        } catch (\RuntimeException) {
        }
        try {
            $r->switchRelease('9.9.9', '127.0.0.1');
            $this->fail('Trebuia refuzat.');
        } catch (\RuntimeException) {
        }

        $r->setMaintenance(true, '127.0.0.1');
        $this->assertTrue($r->status()['maintenance']);
        $down = json_decode(file_get_contents($this->deployRoot.'/shared/storage/framework/down'), true);
        $this->assertSame(503, $down['status']);
        $r->setMaintenance(false, '127.0.0.1');
        $this->assertFalse($r->status()['maintenance']);
    }

    public function test_disabled_by_marker_file(): void
    {
        touch($this->deployRoot.'/shared/keys/recovery.disabled');
        $this->assertFalse((new SevaliaRecovery($this->deployRoot))->isEnabled());
    }

    public function test_works_over_http_without_the_application(): void
    {
        // Versiunea activă este stricată: nu are vendor/ (aplicația nu ar porni).
        @unlink($this->deployRoot.'/releases/0.2.0/vendor/autoload.php');
        $docroot = $this->deployRoot.'/docroot';
        mkdir($docroot);
        copy(base_path('deploy/public_html/recovery.php'), $docroot.'/recovery.php');

        $port = random_int(20000, 40000);
        $env = ['SEVALIA_DEPLOY_PATH' => $this->deployRoot, 'PATH' => getenv('PATH')];
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $docroot, $env);
        try {
            $client = new Client(['base_uri' => "http://127.0.0.1:{$port}", 'cookies' => $jar = new CookieJar, 'http_errors' => false, 'timeout' => 5, 'proxy' => '']);
            $page = null;
            for ($i = 0; $i < 50; $i++) {
                try {
                    $page = $client->get('/recovery.php');
                    break;
                } catch (\Throwable) {
                    usleep(100000);
                }
            }
            $this->assertNotNull($page);
            $html = (string) $page->getBody();
            $this->assertStringContainsString('Cheie de recuperare', $html);
            $this->assertSame("default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'", $page->getHeaderLine('Content-Security-Policy'));
            preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m);

            $bad = (string) $client->post('/recovery.php', ['form_params' => ['csrf' => $m[1], 'action' => 'login', 'key' => 'GRESIT']])->getBody();
            $this->assertStringContainsString('nu este corectă', $bad);

            $noCsrf = $client->post('/recovery.php', ['form_params' => ['csrf' => 'x', 'action' => 'login', 'key' => $this->key]]);
            $this->assertSame(419, $noCsrf->getStatusCode());

            $ok = (string) $client->post('/recovery.php', ['form_params' => ['csrf' => $m[1], 'action' => 'login', 'key' => $this->key]])->getBody();
            $this->assertStringContainsString('Versiune activă: <strong>0.2.0</strong>', $ok);
            preg_match('/name="csrf" value="([a-f0-9]+)"/', $ok, $m2);

            $switched = (string) $client->post('/recovery.php', ['form_params' => ['csrf' => $m2[1], 'action' => 'switch', 'release' => '0.1.0']])->getBody();
            $this->assertStringContainsString('Versiunea activă a fost schimbată', $switched);
            $this->assertSame('0.1.0', json_decode(file_get_contents($this->deployRoot.'/current.json'), true)['release']);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
}
