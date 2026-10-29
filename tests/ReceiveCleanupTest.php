<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Restore\ReceiveCleanup;
use PanelAlpha\Migrator\Restore\ReceiveFinalizer;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Tests\Support\ReceivesMigration;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class ReceiveCleanupTest extends TestCase
{
    use ReceivesMigration;
    use ResetsGlobalState;
    use TemporaryDirectory;

    private const ID = 'mig_20260101_000000_0123456789abcdef0123456789abcdef';
    private const OTHER_ID = 'mig_20260102_000000_fedcba9876543210fedcba9876543210';

    protected function setUp(): void
    {
        $this->setUpDestination();
    }

    private function cleanup(): ReceiveCleanup
    {
        return new ReceiveCleanup($this->wpdb);
    }

    private function age(string $migrationId, int $seconds): void
    {
        touch($this->migrationDir . 'collected/' . $migrationId . '/status.json', time() - $seconds);
    }

    public function testAnAbandonedMigrationIsRemovedAfterTwoDays(): void
    {
        $this->receiveWholeMigration(self::ID);
        $temp = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['temp'];

        $this->age(self::ID, 3600);
        $this->assertSame(0, $this->cleanup()->run());
        $this->assertDirectoryExists($this->migrationDir . self::ID . '/staged');

        $this->age(self::ID, 3 * 86400);
        $this->assertSame(1, $this->cleanup()->run());

        $this->assertContains("DROP TABLE IF EXISTS `{$temp}`", $this->wpdb->queries);
        $this->assertDirectoryDoesNotExist($this->migrationDir . self::ID);
        $this->assertDirectoryDoesNotExist($this->migrationDir . 'collected/' . self::ID);
    }

    public function testOnlyTablesOfTheMapAreDropped(): void
    {
        $this->receiveWholeMigration(self::ID);
        $this->age(self::ID, 3 * 86400);

        $this->cleanup()->run();

        foreach ($this->wpdb->queries as $query) {
            if (str_starts_with($query, 'DROP TABLE IF EXISTS') && !str_contains($query, 'probe')) {
                $this->assertStringContainsString('`pmt_', $query);
            }
        }
        $this->assertContains('wp_options', $this->wpdb->tables());
    }

    public function testAMigrationWhoseFinalizeStoppedHalfwayIsLeftAlone(): void
    {
        $this->receiveWholeMigration(self::ID);
        ReceiveFinalizer::writeJournal(self::ID, ['state' => 'files_moved']);
        $this->age(self::ID, 30 * 86400);

        $this->assertSame(0, $this->cleanup()->run());
        $this->cleanup()->discardUnfinished(self::OTHER_ID);

        $this->assertDirectoryExists($this->migrationDir . self::ID . '/staged');
        $this->assertSame('collecting', (new MigrationRegistry(self::ID))->getStatus()['status']);
    }

    public function testAKeptBackupIsRemovedAfterSevenDays(): void
    {
        $this->receiveWholeMigration(self::ID, ['keep_backup' => true]);
        (new ReceiveFinalizer($this->wpdb, $this->abspath))->finalize(self::ID);
        $backup = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['backup'];
        $this->assertDirectoryExists($this->migrationDir . self::ID . '/replaced');

        $this->age(self::ID, 6 * 86400);
        $this->assertSame(0, $this->cleanup()->run());
        $this->assertNotContains("DROP TABLE IF EXISTS `{$backup}`", $this->wpdb->queries);

        $this->age(self::ID, 8 * 86400);
        $this->assertSame(1, $this->cleanup()->run());

        $this->assertContains("DROP TABLE IF EXISTS `{$backup}`", $this->wpdb->queries);
        $this->assertDirectoryDoesNotExist($this->migrationDir . self::ID);
    }

    public function testFinalizingTheNextMigrationRemovesTheEarlierKeptBackup(): void
    {
        $this->receiveWholeMigration(self::ID, ['keep_backup' => true]);
        (new ReceiveFinalizer($this->wpdb, $this->abspath))->finalize(self::ID);
        $backup = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['backup'];
        $this->assertNotContains("DROP TABLE IF EXISTS `{$backup}`", $this->wpdb->queries);

        self::writeFile($this->abspath . 'wp-content/plugins/demo/old.php', 'live again');
        $this->receiveWholeMigration(self::OTHER_ID, ['keep_backup' => true]);
        (new ReceiveFinalizer($this->wpdb, $this->abspath))->finalize(self::OTHER_ID);

        $this->assertContains("DROP TABLE IF EXISTS `{$backup}`", $this->wpdb->queries);
        $this->assertDirectoryDoesNotExist($this->migrationDir . self::ID);
        $this->assertDirectoryExists($this->migrationDir . self::OTHER_ID . '/replaced');
        $this->assertTrue(ReceiveFinalizer::readJournal(self::ID)['backup_removed']);
    }
}
