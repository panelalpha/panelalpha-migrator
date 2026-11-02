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

    public function testTokenIsSavedOnlyAsAnOptionAndNoPhpFileIsWritten(): void
    {
        $migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $migrationDir);
        $storage = new MigrationStorageService();

        $storage->saveMigrationData('mig_1', ['token' => 'destination-secret', 'destination_url' => 'https://new.example.com']);

        $this->assertStringNotContainsString('destination-secret', file_get_contents($migrationDir . 'mig_1.json'));
        $this->assertSame(['token' => 'destination-secret'], $GLOBALS['wpm_options']['panelalpha_migrator_secret_mig_1']);
        $this->assertFalse($GLOBALS['wpm_option_autoload']['panelalpha_migrator_secret_mig_1']);
        $this->assertFileDoesNotExist($migrationDir . 'mig_1.secret.php');
        $this->assertSame([], glob($migrationDir . '*.php'));
        $this->assertSame('destination-secret', $storage->loadMigrationData('mig_1')['token']);
    }

    public function testSaveFailsWhenTheOptionCannotBeStored(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $GLOBALS['wpm_options_read_only'] = true;

        $this->expectException(\RuntimeException::class);
        (new MigrationStorageService())->saveMigrationData('mig_1', ['token' => 'destination-secret']);
    }

    public function testSecretFileFromAnOlderVersionIsStillRead(): void
    {
        $migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $migrationDir);
        file_put_contents($migrationDir . 'mig_1.secret.php', "<?php exit; ?>\n" . json_encode(['token' => 'old-secret']));

        $this->assertSame(['token' => 'old-secret'], (new MigrationStorageService())->loadSecrets('mig_1'));
    }

    public function testOptionWinsOverAnOldSecretFile(): void
    {
        $migrationDir = $this->createTemporaryDirectory();
        Config::set('migration_dir', $migrationDir);
        file_put_contents($migrationDir . 'mig_1.secret.php', "<?php exit; ?>\n" . json_encode(['token' => 'old-secret']));
        $GLOBALS['wpm_options']['panelalpha_migrator_secret_mig_1'] = ['token' => 'new-secret'];

        $this->assertSame(['token' => 'new-secret'], (new MigrationStorageService())->loadSecrets('mig_1'));
    }
}
