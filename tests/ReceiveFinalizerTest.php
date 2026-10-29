<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Restore\MigrationConflictException;
use PanelAlpha\Migrator\Restore\MigrationNotFoundException;
use PanelAlpha\Migrator\Restore\ReceiveFinalizer;
use PanelAlpha\Migrator\Restore\StagedFiles;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Tests\Support\ReceivesMigration;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class ReceiveFinalizerTest extends TestCase
{
    use ReceivesMigration;
    use ResetsGlobalState;
    use TemporaryDirectory;

    private const ID = 'mig_20260101_000000_0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $this->setUpDestination();
    }

    private function finalizer(): ReceiveFinalizer
    {
        return new ReceiveFinalizer($this->wpdb, $this->abspath);
    }

    private function swapQueries(): array
    {
        return array_values(array_filter($this->wpdb->queries, static function (string $query): bool {
            return str_starts_with($query, 'RENAME TABLE `wp_options`');
        }));
    }

    public function testFinalizeMovesTheFilesAndSwapsTheTables(): void
    {
        $this->receiveWholeMigration(self::ID);
        $map = (new MigrationRegistry(self::ID))->getTableMap();
        $temp = $map['wp_options']['temp'];
        $backup = $map['wp_options']['backup'];

        $outcome = $this->finalizer()->finalize(self::ID);

        $this->assertTrue($outcome['finalized']);
        $this->assertSame('migration_completed', $outcome['result']['action']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/demo/demo.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/demo/old.php');
        $this->assertSame(["RENAME TABLE `wp_options` TO `{$backup}`, `{$temp}` TO `wp_options`"], $this->swapQueries());
        $this->assertFileDoesNotExist($this->abspath . '.maintenance');
        $this->assertSame('restored', (new MigrationRegistry(self::ID))->getStatus()['status']);
        $this->assertSame('done', ReceiveFinalizer::readJournal(self::ID)['state']);
    }

    public function testTheSiteIsInMaintenanceModeWhileTheTablesAreSwapped(): void
    {
        $this->receiveWholeMigration(self::ID);
        $seen = null;
        $abspath = $this->abspath;
        $wpdb = new class ($this->wpdb, static function () use ($abspath, &$seen): void {
            $seen = file_exists($abspath . '.maintenance');
        }) {
            private $inner;
            private $onRename;

            public function __construct($inner, callable $onRename)
            {
                $this->inner = $inner;
                $this->onRename = $onRename;
            }

            public function __get($name)
            {
                return $this->inner->$name;
            }

            public function __set($name, $value)
            {
                $this->inner->$name = $value;
            }

            public function __call($name, $arguments)
            {
                if ($name === 'query' && str_starts_with($arguments[0], 'RENAME TABLE `wp_options`')) {
                    ($this->onRename)();
                }

                return $this->inner->$name(...$arguments);
            }
        };

        (new ReceiveFinalizer($wpdb, $this->abspath))->finalize(self::ID);

        $this->assertTrue($seen);
    }

    public function testThePluginStaysActiveInTheNewOptions(): void
    {
        $this->receiveWholeMigration(self::ID);

        $this->finalizer()->finalize(self::ID);

        $updates = array_values(array_filter($this->wpdb->queries, static function (string $query): bool {
            return str_starts_with($query, 'UPDATE ');
        }));
        $this->assertCount(1, $updates);
        // The list is sorted, so the order depends on the plugin folder name.
        $expected = ['hello.php', basename(rtrim(\PanelAlpha\Migrator\Helpers\PathProvider::getPluginDir(), '/')) . '/panelalpha-migrator.php'];
        sort($expected);
        $this->assertStringContainsString(serialize($expected), $updates[0]);
    }

    private function addOption(string $table, string $name, string $value): void
    {
        $this->wpdb->sqlite('INSERT INTO "' . $table . '" (option_name, option_value, autoload) VALUES (' . "'" . $name . "', '" . $value . "', 'no')");
    }

    private function optionRows(string $table, string $name): array
    {
        return $this->wpdb->rows('SELECT option_value, autoload FROM "' . $table . '" WHERE option_name = \'' . $name . '\'');
    }

    public function testThisSitesTokenAndSettingsSurviveTheTableSwap(): void
    {
        $this->receiveWholeMigration(self::ID);
        $temp = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['temp'];
        $this->wpdb->sqlite("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('panelalpha_migrator_token', 'destination-token', 'no'), ('panelalpha_migrator_settings', 'destination-settings', 'no')");
        $this->addOption($temp, 'panelalpha_migrator_token', 'source-token');
        $this->addOption($temp, 'panelalpha_migrator_settings', 'source-settings');
        $this->addOption($temp, 'blogname', 'Source');

        $this->finalizer()->finalize(self::ID);

        // The swap itself is not run by the fake, so the temporary table is what becomes the live one.
        $this->assertSame([['destination-token', 'no']], $this->optionRows($temp, 'panelalpha_migrator_token'));
        $this->assertSame([['destination-settings', 'no']], $this->optionRows($temp, 'panelalpha_migrator_settings'));
        $this->assertSame([['Source', 'no']], $this->optionRows($temp, 'blogname'));
    }

    public function testADestinationWithoutSettingsEndsWithoutThem(): void
    {
        $this->receiveWholeMigration(self::ID);
        $temp = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['temp'];
        $this->wpdb->sqlite("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('panelalpha_migrator_token', 'destination-token', 'no')");
        $this->addOption($temp, 'panelalpha_migrator_token', 'source-token');
        $this->addOption($temp, 'panelalpha_migrator_settings', 'source-settings');

        $this->finalizer()->finalize(self::ID);

        $this->assertSame([['destination-token', 'no']], $this->optionRows($temp, 'panelalpha_migrator_token'));
        $this->assertSame([], $this->optionRows($temp, 'panelalpha_migrator_settings'));
    }

    public function testAFailedCopyOfTheSettingsStopsTheFinalize(): void
    {
        $this->receiveWholeMigration(self::ID);
        $this->wpdb->failOn = 'INSERT INTO';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to keep the token and settings');

        try {
            $this->finalizer()->finalize(self::ID);
        } finally {
            $this->assertSame([], $this->swapQueries());
        }
    }

    public function testSkippedFilesAreReportedWithAWarning(): void
    {
        $this->receiveWholeMigration(self::ID, ['files' => [
            ['path' => 'wp-content/plugins/demo/demo.php', 'size' => 3, 'total_chunks' => 1],
            ['path' => 'wp-content/uploads/gone.jpg', 'size' => 3, 'total_chunks' => 1],
        ]]);

        $outcome = $this->finalizer()->finalize(self::ID, ['wp-content/uploads/gone.jpg', 'not-in-the-manifest']);

        $this->assertSame(['wp-content/uploads/gone.jpg'], $outcome['result']['skipped_files']);
        $this->assertArrayHasKey('warning', $outcome['result']);
    }

    public function testFinalizingAgainAnswersWithTheEarlierResultAndDoesNotSwapAgain(): void
    {
        $this->receiveWholeMigration(self::ID);
        $first = $this->finalizer()->finalize(self::ID);

        $second = $this->finalizer()->finalize(self::ID);

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->swapQueries());
    }

    public function testFailedSwapPutsTheFilesBackAndKeepsTheLiveSite(): void
    {
        $this->receiveWholeMigration(self::ID);
        $this->wpdb->failOn = 'RENAME TABLE';

        try {
            $this->finalizer()->finalize(self::ID);
            $this->fail('Expected the failed swap to stop the finalize');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to swap the tables', $e->getMessage());
        }

        $this->assertSame('old', file_get_contents($this->abspath . 'wp-content/plugins/demo/old.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/demo/demo.php');
        $this->assertFileDoesNotExist($this->abspath . '.maintenance');
        $this->assertSame('finalize_failed', (new MigrationRegistry(self::ID))->getStatus()['status']);
        $this->assertSame('failed', ReceiveFinalizer::readJournal(self::ID)['state']);
        $this->assertContains((new MigrationRegistry(self::ID))->getTableMap()['wp_options']['temp'], $this->wpdb->tables());
    }

    public function testAFailedFinalizeCanBeTriedAgain(): void
    {
        $this->receiveWholeMigration(self::ID);
        $this->wpdb->failOn = 'RENAME TABLE';

        try {
            $this->finalizer()->finalize(self::ID);
        } catch (\RuntimeException $e) {
        }

        $this->wpdb->failOn = null;
        $outcome = $this->finalizer()->finalize(self::ID);

        $this->assertTrue($outcome['finalized']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/demo/demo.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/demo/old.php');
    }

    public function testAFinalizeThatStoppedAfterTheFilesMovedIsFinished(): void
    {
        $this->receiveWholeMigration(self::ID);
        $staged = new StagedFiles($this->abspath, $this->migrationDir . self::ID);
        $staged->moveIntoPlace();
        ReceiveFinalizer::writeJournal(self::ID, ['state' => 'files_moved', 'moved' => 1, 'warnings' => [], 'skipped_files' => []]);

        $outcome = $this->finalizer()->finalize(self::ID);

        $this->assertTrue($outcome['finalized']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/demo/demo.php'));
        $this->assertCount(1, $this->swapQueries());
        $this->assertSame('done', ReceiveFinalizer::readJournal(self::ID)['state']);
    }

    public function testAFinalizeThatStoppedAfterTheSwapOnlyEndsTheWork(): void
    {
        $this->receiveWholeMigration(self::ID);
        $staged = new StagedFiles($this->abspath, $this->migrationDir . self::ID);
        $staged->moveIntoPlace();
        ReceiveFinalizer::writeJournal(self::ID, ['state' => 'files_moved', 'moved' => 1, 'warnings' => [], 'skipped_files' => []]);
        // The swap went through: the temporary table is the live table now.
        $this->wpdb->sqlite('DROP TABLE "' . (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['temp'] . '"');

        $outcome = $this->finalizer()->finalize(self::ID);

        $this->assertTrue($outcome['finalized']);
        $this->assertSame([], $this->swapQueries());
        $this->assertContains('DROP TABLE IF EXISTS `' . (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['backup'] . '`', $this->wpdb->queries);
        $this->assertSame('restored', (new MigrationRegistry(self::ID))->getStatus()['status']);
    }

    public function testWhileAnotherCallIsFinishingTheAnswerIsNotFinalized(): void
    {
        $this->receiveWholeMigration(self::ID);
        $lock = fopen($this->migrationDir . 'collected/' . self::ID . '/status.lock', 'c');
        flock($lock, LOCK_EX);

        $outcome = $this->finalizer()->finalize(self::ID);

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(['finalized' => false, 'phase' => 'finalizing'], $outcome);
        $this->assertFileExists($this->abspath . 'wp-content/plugins/demo/old.php');
        $this->assertSame([], $this->swapQueries());
    }

    public function testAnIncompleteMigrationIsNotFinalized(): void
    {
        (new \PanelAlpha\Migrator\Restore\ReceiveStarter($this->wpdb))->start(self::ID, $this->infoChunk(self::ID));

        $this->expectException(MigrationConflictException::class);
        $this->expectExceptionMessage('Migration manifest is incomplete');

        $this->finalizer()->finalize(self::ID);
    }

    public function testAnUnknownMigrationIsNotFound(): void
    {
        $this->expectException(MigrationNotFoundException::class);

        $this->finalizer()->finalize(self::ID);
    }

    public function testAReplacedMigrationIsNotFinalized(): void
    {
        $this->receiveWholeMigration(self::ID);
        (new MigrationRegistry(self::ID))->updateStatus('abandoned');

        $this->expectException(MigrationConflictException::class);

        $this->finalizer()->finalize(self::ID);
    }

    public function testTheBackupIsDroppedUnlessTheMigrationKeepsIt(): void
    {
        $this->receiveWholeMigration(self::ID);
        $backup = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['backup'];

        $this->finalizer()->finalize(self::ID);

        $this->assertContains("DROP TABLE IF EXISTS `{$backup}`", $this->wpdb->queries);
        $this->assertDirectoryDoesNotExist($this->migrationDir . self::ID . '/replaced');
    }

    public function testAKeptBackupStaysAfterTheSwap(): void
    {
        $this->receiveWholeMigration(self::ID, ['keep_backup' => true]);
        $backup = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['backup'];

        $this->finalizer()->finalize(self::ID);

        $this->assertNotContains("DROP TABLE IF EXISTS `{$backup}`", $this->wpdb->queries);
        $this->assertSame('old', file_get_contents($this->migrationDir . self::ID . '/replaced/wp-content/plugins/demo/old.php'));
    }
}
