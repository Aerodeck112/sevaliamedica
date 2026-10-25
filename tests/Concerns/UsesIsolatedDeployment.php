<?php

namespace Tests\Concerns;

use App\Modules\Backup\Crypto\BackupKey;
use Illuminate\Support\Facades\DB;

/**
 * Mediu izolat pentru testele de backup/actualizare: director temporar de deployment (releases/, shared/,
 * public_html/) și o bază de date separată „a clinicii” (conexiunea clinic_data), ca operațiile DDL
 * (migrări, restaurări) să nu interfereze cu tranzacția de test a conexiunii implicite.
 */
trait UsesIsolatedDeployment
{
    protected string $deployRoot;

    protected function setUpIsolatedDeployment(bool $withRelease = true): void
    {
        $this->deployRoot = sys_get_temp_dir().'/sevalia-test-'.bin2hex(random_bytes(6));
        mkdir($this->deployRoot.'/releases', 0755, true);
        mkdir($this->deployRoot.'/public_html', 0755, true);

        config([
            'updates.deploy_path' => $this->deployRoot,
            'updates.shared_path' => $this->deployRoot.'/shared',
            'updates.public_path' => $this->deployRoot.'/public_html',
            'updates.connection' => 'clinic_data',
            'updates.step_time_budget' => 5,
        ]);
        app(\App\Modules\Updates\Support\DeploymentLayout::class)->ensureSharedDirectories();
        BackupKey::generate($this->deployRoot.'/shared/keys');

        $this->makeDatabaseConnection('clinic_data', 'data');
        $this->makeDatabaseConnection('restore_target', 'restore');

        if ($withRelease) {
            $this->fakeRelease('0.1.0');
            file_put_contents($this->deployRoot.'/current.json', json_encode(['release' => '0.1.0', 'previous' => null, 'activated_at' => gmdate('c')]));
        }
    }

    protected function tearDownIsolatedDeployment(): void
    {
        if (isset($this->deployRoot) && is_dir($this->deployRoot)) {
            $this->removeDirectory($this->deployRoot);
        }
        DB::disconnect('clinic_data');
        DB::disconnect('restore_target');
    }

    protected function makeDatabaseConnection(string $name, string $suffix): void
    {
        $database = config('database.connections.mysql.database').'_'.$suffix;
        DB::connection()->statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(["database.connections.{$name}" => array_merge(config('database.connections.mysql'), ['database' => $database])]);
        DB::purge($name);
        // Bază goală la fiecare test.
        $conn = DB::connection($name);
        $conn->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($conn->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $table = array_values((array) $row)[0];
            $conn->statement("DROP TABLE `{$table}`");
        }
        $conn->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Un release minimal, suficient pentru comutarea pointerului (vendor/autoload.php + VERSION). */
    protected function fakeRelease(string $version, array $files = []): string
    {
        $dir = $this->deployRoot.'/releases/'.$version;
        @mkdir($dir.'/vendor', 0755, true);
        @mkdir($dir.'/public/build', 0755, true);
        file_put_contents($dir.'/vendor/autoload.php', "<?php\n");
        file_put_contents($dir.'/VERSION', $version."\n");
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir.'/'.$path), 0755, true);
            file_put_contents($dir.'/'.$path, $content);
        }

        return $dir;
    }

    protected function removeDirectory(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
