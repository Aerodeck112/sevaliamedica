<?php

namespace Tests\Unit\Updates;

use App\Modules\Updates\Package\Manifest;
use App\Modules\Updates\Package\PackageException;
use App\Modules\Updates\Package\PackageInspector;
use App\Modules\Updates\Package\SignatureVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PackageNamesTest extends TestCase
{
    private function inspector(): PackageInspector
    {
        return new PackageInspector(new SignatureVerifier([]), [], ['app', 'vendor', 'public', 'artisan']);
    }

    public static function unsafeNames(): array
    {
        return [
            'traversare' => ['payload/app/../../etc/passwd'],
            'absolută' => ['/etc/passwd'],
            'windows' => ['C:/Windows/x.php'],
            'backslash' => ['payload\\app\\x.php'],
            'nul' => ["payload/app/x\0.php"],
            'control' => ["payload/app/x\n.php"],
            'punct' => ['payload/./app/x.php'],
            'segment gol' => ['payload//app/x.php'],
            'rădăcină nepermisă' => ['payload/storage/x.php'],
            'în afara payload' => ['app/x.php'],
            '.env' => ['payload/.env'],
        ];
    }

    #[DataProvider('unsafeNames')]
    public function test_unsafe_names_are_rejected(string $name): void
    {
        $this->expectException(PackageException::class);
        $this->inspector()->assertSafeName($name);
    }

    public function test_safe_names_are_accepted(): void
    {
        foreach (['manifest.json', 'manifest.sig', 'payload/', 'payload/app/Models/User.php', 'payload/artisan', 'payload/vendor/'] as $name) {
            $this->inspector()->assertSafeName($name);
        }
        $this->assertTrue(true);
    }

    public function test_manifest_requires_upgrade_range_for_updates(): void
    {
        $this->expectExceptionMessage('versiunile de la care se poate actualiza');
        Manifest::parse(json_encode(['format' => 1, 'product' => 'sevalia', 'version' => '1.0.0', 'type' => 'update',
            'requires' => ['php' => '8.3.0', 'extensions' => [], 'database' => []], 'files' => []]));
    }

    public function test_version_compatibility_rules(): void
    {
        $m = Manifest::parse(json_encode(['format' => 1, 'product' => 'sevalia', 'version' => '0.3.0', 'type' => 'update',
            'upgrade_from' => ['min' => '0.2.0', 'max' => '0.2.99'],
            'requires' => ['php' => '8.3.0', 'extensions' => [], 'database' => []],
            'files' => [['path' => 'payload/VERSION', 'sha256' => str_repeat('a', 64), 'size' => 6]]]));

        $this->assertNull($m->incompatibilityWith('0.2.5'));
        $this->assertNotNull($m->incompatibilityWith('0.1.0'));
        $this->assertNotNull($m->incompatibilityWith('0.3.0'));
        $this->assertNotNull($m->incompatibilityWith('0.4.0'));
    }
}
