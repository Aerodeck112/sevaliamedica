<?php

namespace Tests\Feature\Updates;

use App\Modules\Updates\Models\UpdateRun;
use App\Modules\Updates\Support\DiskSpace;
use App\Modules\Updates\Updater\UpdateRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\BuildsTestPackages;
use Tests\Concerns\UsesIsolatedDeployment;
use Tests\TestCase;

class UpdateFlowTest extends TestCase
{
    use BuildsTestPackages, UsesIsolatedDeployment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedDeployment();
        $this->setUpTestPackages();
        $this->seedExistingClinicData();
    }

    protected function tearDown(): void
    {
        if (app()->isDownForMaintenance()) {
            app()->maintenanceMode()->deactivate();
        }
        $this->tearDownTestPackages();
        $this->tearDownIsolatedDeployment();
        parent::tearDown();
    }

    /** Date „existente” ale clinicii în versiunea 0.1.0 (fictive): pagini CMS și tarife editate. */
    private function seedExistingClinicData(): void
    {
        $db = DB::connection('clinic_data');
        $db->statement('CREATE TABLE pagini_cms (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(100), continut TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->statement('CREATE TABLE tarife (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, serviciu VARCHAR(100), pret DECIMAL(10,2)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->table('pagini_cms')->insert([['slug' => 'kinetoterapie', 'continut' => 'Text editat de clinică — Șț'], ['slug' => 'contactus', 'continut' => 'Program editat']]);
        $db->table('tarife')->insert([['serviciu' => 'Serviciu fictiv A', 'pret' => 123.45]]);
    }

    private function clinicSnapshot(): array
    {
        $db = DB::connection('clinic_data');

        return [
            'pagini' => $db->table('pagini_cms')->orderBy('id')->get()->map(fn ($r) => [$r->slug, $r->continut])->all(),
            'tarife' => $db->table('tarife')->orderBy('id')->get()->map(fn ($r) => [$r->serviciu, $r->pret])->all(),
        ];
    }

    private function runner(): UpdateRunner
    {
        return app(UpdateRunner::class);
    }

    private function pointer(): array
    {
        return json_decode(file_get_contents($this->deployRoot.'/current.json'), true);
    }

    public function test_successful_update_keeps_existing_data(): void
    {
        $before = $this->clinicSnapshot();
        $zip = $this->buildPackage('0.2.0', [
            'database/migrations/2026_10_20_000001_adauga_observatii.php' => self::migration("Schema::table('tarife', fn (Blueprint \$t) => \$t->string('observatii')->nullable());"),
        ]);

        $run = $this->runner()->prepare($zip);
        $this->assertSame('prepared', $run->status);
        $this->assertTrue(collect($run->preflight)->every(fn ($c) => $c['status'] !== 'fail'), json_encode($run->preflight));

        $run = $this->runner()->runToEnd($this->runner()->start($run));

        $this->assertSame('completed', $run->status, (string) $run->error);
        $this->assertSame(['release' => '0.2.0', 'previous' => '0.1.0'], array_intersect_key($this->pointer(), ['release' => 1, 'previous' => 1]));
        $this->assertSame($before, $this->clinicSnapshot(), 'Datele clinicii (CMS, tarife) rămân neschimbate.');
        $this->assertTrue(Schema::connection('clinic_data')->hasColumn('tarife', 'observatii'));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame('verified', $run->backup->status);
        $this->assertFileExists($this->deployRoot.'/releases/0.2.0/.shared-path');
        $this->assertFileExists($this->deployRoot.'/public_html/build/assets/panou-0.2.0.css');
        $this->assertFileExists($this->deployRoot.'/public_html/recovery.php');
        $this->assertFileDoesNotExist($this->deployRoot.'/public_html/index.php', 'public/index.php din release nu se publică.');
        $this->assertDirectoryDoesNotExist($this->deployRoot.'/shared/updates/'.$run->uuid.'/staging');
        $this->assertDirectoryExists($this->deployRoot.'/releases/0.1.0', 'Versiunea anterioară se păstrează pentru revenire.');
        $this->assertNull($run->active_slot);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updates.completed']);
        $journal = json_decode(file_get_contents($this->deployRoot.'/shared/updates/'.$run->uuid.'/journal.json'), true);
        $this->assertSame('completed', $journal['run']['status']);
    }

    public function test_failed_migration_keeps_maintenance_and_does_not_touch_database_automatically(): void
    {
        $before = $this->clinicSnapshot();
        $zip = $this->buildPackage('0.2.0', [
            'database/migrations/2026_10_20_000001_tabela_noua.php' => self::migration("Schema::create('tabela_noua', fn (Blueprint \$t) => \$t->id());"),
            'database/migrations/2026_10_20_000002_esueaza.php' => self::migration("throw new \\RuntimeException('Migrare eșuată intenționat (test).');"),
        ]);
        $run = $this->runner()->runToEnd($this->runner()->start($this->runner()->prepare($zip)));

        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', $run->step('migrate')['status']);
        $this->assertSame('0.1.0', $this->pointer()['release'], 'Codul vechi rămâne activ.');
        $this->assertTrue(app()->isDownForMaintenance(), 'Aplicația rămâne în mentenanță.');
        $this->assertTrue(Schema::connection('clinic_data')->hasTable('tabela_noua'), 'Baza NU este restaurată automat.');
        $this->assertNotNull($run->active_slot, 'Actualizarea eșuată blochează alte actualizări până la decizie.');

        $options = $this->runner()->recoveryOptions($run);
        $this->assertArrayHasKey('restore_db', $options);
        $this->assertArrayHasKey('retry', $options);
        $this->assertArrayHasKey('maintenance_off', $options);
        $this->assertStringContainsString('în mentenanță continuu', $options['restore_db']['detail']);

        // Decizia explicită: restaurarea backupului de dinainte.
        while (! $this->runner()->restoreDatabase($run->refresh(), microtime(true) - 1)) {
            // pași minimi, ca o restaurare lungă împărțită în cereri
        }
        $run->refresh();
        $this->assertSame('rolled_back', $run->status);
        $this->assertFalse(Schema::connection('clinic_data')->hasTable('tabela_noua'), 'Restaurarea exactă elimină tabelele create după backup.');
        $this->assertSame($before, $this->clinicSnapshot());
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertNull($run->active_slot);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updates.db_restored']);
    }

    public function test_failure_before_migrations_rolls_back_code_automatically(): void
    {
        $runner = $this->runner();
        $run = $runner->start($runner->prepare($this->buildPackage('0.2.0')));
        // Extragere completă, apoi un fișier din staging este alterat (ex. disc defect) → copierea detectează.
        while ($run->step('extract')['status'] !== 'done') {
            $run = $runner->advance($run);
        }
        file_put_contents($this->deployRoot.'/shared/updates/'.$run->uuid.'/staging/payload/app/Exemplu.php', 'alterat');
        $run = $runner->runToEnd($run);

        $this->assertSame('rolled_back', $run->status);
        $this->assertStringContainsString('Suma de control nu corespunde după copiere', $run->step('copy_release')['error']);
        $this->assertSame('0.1.0', $this->pointer()['release']);
        $this->assertDirectoryDoesNotExist($this->deployRoot.'/releases/0.2.0.partial');
        $this->assertDirectoryDoesNotExist($this->deployRoot.'/releases/0.2.0');
        $this->assertFalse(app()->isDownForMaintenance(), 'Fără modificări în bază, aplicația iese din mentenanță.');
        $this->assertNull($run->active_slot);
    }

    public function test_health_failure_after_activation_reverts_code(): void
    {
        $this->fakeHealth(false);
        $run = $this->runner()->runToEnd($this->runner()->start($this->runner()->prepare($this->buildPackage('0.2.0'))));

        $this->assertSame('rolled_back', $run->status, (string) $run->error);
        $this->assertSame('0.1.0', $this->pointer()['release']);
        $this->assertSame('reverted', $run->step('activate')['status']);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_interrupted_update_resumes_from_where_it_stopped(): void
    {
        $runner = $this->runner();
        $run = $runner->start($runner->prepare($this->buildPackage('0.2.0', [
            'app/Mult/A.php' => '<?php // a', 'app/Mult/B.php' => '<?php // b', 'app/Mult/C.php' => '<?php // c',
        ])));
        // Termen expirat: fiecare apel face o singură unitate de lucru (ca la un timeout de PHP).
        for ($i = 0; $i < 200 && $run->step('copy_release')['status'] !== 'running'; $i++) {
            $run = $runner->advance($run, microtime(true) - 1);
        }
        $run = $runner->advance($run, microtime(true) - 1);
        $cursor = $run->step('copy_release')['data']['cursor'];
        $this->assertGreaterThan(0, $cursor);
        // „Procesul moare” în timpul copierii unui fișier: rămâne un fișier temporar parțial.
        file_put_contents($this->deployRoot.'/releases/0.2.0.partial/app/Exemplu.php.tmp', 'parțial');

        // Un proces nou (instanță nouă) reia de unde s-a rămas.
        $this->app->forgetInstance(UpdateRunner::class);
        $run = app(UpdateRunner::class)->runToEnd(UpdateRun::find($run->id));

        $this->assertSame('completed', $run->status, (string) $run->error);
        $this->assertGreaterThan(1, $run->step('copy_release')['attempts']);
        $this->assertSame('<?php // b', file_get_contents($this->deployRoot.'/releases/0.2.0/app/Mult/B.php'));
    }

    public function test_concurrent_updates_are_blocked(): void
    {
        $runner = $this->runner();
        $first = $runner->prepare($this->buildPackage('0.2.0'));
        $second = $runner->prepare($this->buildPackage('0.2.1'));
        $runner->start($first);

        try {
            $runner->start($second);
            $this->fail('A doua actualizare trebuia blocată.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Verificările preliminare', $e->getMessage());
        }
        $this->assertSame('fail', collect($second->refresh()->preflight)->firstWhere('key', 'concurrency')['status']);

        // Blocarea pe fișier împiedică doi pași simultani ai aceleiași actualizări.
        $fh = fopen($this->deployRoot.'/shared/updates/.lock', 'c');
        flock($fh, LOCK_EX);
        try {
            $this->expectExceptionMessage('Un alt pas al actualizării rulează');
            $runner->advance($first);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function test_insufficient_disk_space_blocks_start_without_changes(): void
    {
        $run = $this->runner()->prepare($this->buildPackage('0.2.0'));
        $this->app->instance(DiskSpace::class, new class implements DiskSpace
        {
            public function freeBytes(string $path): ?int
            {
                return 1000;
            }
        });
        $this->app->forgetInstance(UpdateRunner::class);

        try {
            app(UpdateRunner::class)->start($run);
            $this->fail('Trebuia blocat.');
        } catch (RuntimeException) {
        }
        $this->assertSame('prepared', $run->refresh()->status);
        $this->assertSame('fail', collect($run->preflight)->firstWhere('key', 'disk')['status']);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_incompatible_version_is_blocked(): void
    {
        $run = $this->runner()->prepare($this->buildPackage('0.7.0', meta: ['upgrade_from' => ['min' => '0.5.0', 'max' => '0.6.99']]));
        $check = collect($run->preflight)->firstWhere('key', 'version');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('0.5.0 – 0.6.99', $check['detail']);
        $this->expectException(RuntimeException::class);
        $this->runner()->start($run);
    }

    public function test_development_checkout_can_verify_but_not_install(): void
    {
        config(['updates.deploy_path' => null]);
        $run = $this->runner()->prepare($this->buildPackage('0.2.0'));
        $this->assertSame('fail', collect($run->preflight)->firstWhere('key', 'layout')['status']);
    }
}
