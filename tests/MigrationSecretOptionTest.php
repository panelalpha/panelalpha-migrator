<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationSecretOptionTest extends TestCase
{
    use TemporaryDirectory;

    public function testTokenGoesToTheDatabaseWhenTheHostRefusesTheSecretFile(): void
    {
        eval('namespace { $GLOBALS["wpm_options"] = [];
            function update_option($name, $value, $autoload = null) { $GLOBALS["wpm_options"][$name] = $value; return true; }
            function get_option($name, $default = false) { return $GLOBALS["wpm_options"][$name] ?? $default; } }');
        $migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $migrationDir);
        // A folder in place of the secret file: the file cannot be written, as on WP Engine.
        mkdir($migrationDir . 'mig_1.secret.php');
        $storage = new MigrationStorageService();

        $storage->saveMigrationData('mig_1', ['token' => 'destination-secret', 'destination_url' => 'https://new.example.com']);

        $this->assertStringNotContainsString('destination-secret', file_get_contents($migrationDir . 'mig_1.json'));
        $this->assertSame(['token' => 'destination-secret'], $GLOBALS['wpm_options']['panelalpha_migrator_secret_mig_1']);
        $this->assertSame('destination-secret', $storage->loadMigrationData('mig_1')['token']);
    }
}
