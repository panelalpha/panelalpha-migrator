<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Restore\DatabasePrivilegesException;
use PanelAlpha\Migrator\Restore\TableSwapper;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Tests\Support\RecordingWpdb;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class TableSwapperTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private RecordingWpdb $wpdb;

    private TableSwapper $swapper;

    protected function setUp(): void
    {
        $this->wpdb = new RecordingWpdb();
        $this->swapper = new TableSwapper($this->wpdb);
    }

    public function testSourceNamesMoveToTheDestinationPrefix(): void
    {
        $this->wpdb->base_prefix = 'new_';

        $this->assertSame('new_posts', $this->swapper->destinationName('old_posts', 'old_'));
        $this->assertSame('custom_log', $this->swapper->destinationName('custom_log', 'old_'));
        $this->assertSame('old_posts', $this->swapper->destinationName('old_posts', ''));
    }

    public function testAnEmptyDestinationPrefixIsKept(): void
    {
        $this->wpdb->base_prefix = '';

        $this->assertSame('posts', $this->swapper->destinationName('old_posts', 'old_'));
    }

    public function testMapHoldsTheDestinationTemporaryAndBackupNames(): void
    {
        $this->wpdb->base_prefix = 'new_';

        $map = $this->swapper->buildMap('ab12cd', ['old_posts', 'custom_log'], 'old_');

        $this->assertSame([
            'old_posts' => ['destination' => 'new_posts', 'temp' => 'pmt_ab12cd_new_posts', 'backup' => 'pmb_ab12cd_new_posts'],
            'custom_log' => ['destination' => 'custom_log', 'temp' => 'pmt_ab12cd_custom_log', 'backup' => 'pmb_ab12cd_custom_log'],
        ], $map);
    }

    public function testALongNameEndsWithAHashOfTheDestinationName(): void
    {
        $long = 'wp_' . str_repeat('a', 55);
        $exactlyAtTheLimit = 'wp_' . str_repeat('b', 64 - strlen('pmt_ab12cd_wp_'));

        $map = $this->swapper->buildMap('ab12cd', [$long, $exactlyAtTheLimit], 'wp_');

        $this->assertSame('pmt_ab12cd_' . substr(sha1($long), 0, 40), $map[$long]['temp']);
        $this->assertSame('pmb_ab12cd_' . substr(sha1($long), 0, 40), $map[$long]['backup']);
        $this->assertSame(64, strlen($map[$exactlyAtTheLimit]['temp']));
        $this->assertSame('pmt_ab12cd_' . $exactlyAtTheLimit, $map[$exactlyAtTheLimit]['temp']);
    }

    public function testTwoSourceTablesForOneDestinationTableAreRefused(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Two source tables map to the destination table wp_posts');

        $this->swapper->buildMap('ab12cd', ['old_posts', 'wp_posts'], 'old_');
    }

    public function testCreateMapStoresTheMapAndKeepsTheHashOnASecondCall(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $registry = new MigrationRegistry('mig_1');
        $registry->registerMigration(['tables' => [['name' => 'wp_posts', 'total_chunks' => 1]]]);

        $first = $this->swapper->createMap($registry, ['wp_posts'], 'wp_');
        $hash = $registry->getTableHash();
        $second = $this->swapper->createMap($registry, ['wp_posts'], 'wp_');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $hash);
        $this->assertSame('pmt_' . $hash . '_wp_posts', $first['wp_posts']['temp']);
        $this->assertSame($first, $second);
        $this->assertSame($first, $registry->getTableMap());
    }

    public function testAHashThatIsInUseIsNotChosen(): void
    {
        // The temporary prefix is taken once, the backup prefix is taken once, then a hash is free.
        $this->wpdb->showTablesAnswers = [['pmt_taken_wp_posts'], [], ['pmb_taken_wp_posts']];

        $hash = $this->swapper->chooseHash();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $hash);
        $lookups = array_filter($this->wpdb->queries, static function (string $query): bool {
            return str_starts_with($query, 'SHOW TABLES LIKE');
        });
        $this->assertCount(5, $lookups);
    }

    public function testNoFreeHashIsAnError(): void
    {
        $this->wpdb->showTablesAnswers = array_fill(0, 10, ['pmt_taken_wp_posts']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Could not find a free prefix for the temporary tables');

        $this->swapper->chooseHash();
    }

    public function testPrivilegesAreCheckedWithACreateRenameAndDropOfAProbeTable(): void
    {
        $this->swapper->checkPrivileges('ab12cd');

        $this->assertSame([
            'DROP TABLE IF EXISTS `pmt_ab12cd_probe`, `pmt_ab12cd_probe2`',
            'CREATE TABLE `pmt_ab12cd_probe` (id int) ENGINE=InnoDB',
            'RENAME TABLE `pmt_ab12cd_probe` TO `pmt_ab12cd_probe2`',
            'DROP TABLE IF EXISTS `pmt_ab12cd_probe`, `pmt_ab12cd_probe2`',
        ], $this->wpdb->queries);
    }

    /**
     * @dataProvider privilegeFailures
     */
    #[DataProvider('privilegeFailures')]
    public function testMissingPrivilegesAreReportedAndTheProbeIsCleanedUp(string $failingStatement): void
    {
        $this->wpdb->failOn = $failingStatement;

        try {
            $this->swapper->checkPrivileges('ab12cd');
            $this->fail('A missing privilege must be reported');
        } catch (DatabasePrivilegesException $e) {
            $this->assertSame('Destination database user cannot create or rename tables (needs CREATE, ALTER, DROP)', $e->getMessage());
        }

        $this->assertStringStartsWith('DROP TABLE IF EXISTS', end($this->wpdb->queries));
    }

    public static function privilegeFailures(): array
    {
        return [
            'create' => ['CREATE TABLE'],
            'rename' => ['RENAME TABLE'],
        ];
    }

    public function testSwapRenamesLiveToBackupAndTemporaryToLiveInOneStatement(): void
    {
        $this->useTables(['wp_posts', 'wp_options', 'pmt_ab12cd_wp_posts', 'pmt_ab12cd_wp_options']);

        $result = $this->swapper->swap($this->map(['wp_posts', 'wp_options']));

        $this->assertSame(['swapped' => 2, 'backup_kept' => false], $result);
        $this->assertSame([
            'RENAME TABLE `wp_posts` TO `pmb_ab12cd_wp_posts`, `pmt_ab12cd_wp_posts` TO `wp_posts`, '
                . '`wp_options` TO `pmb_ab12cd_wp_options`, `pmt_ab12cd_wp_options` TO `wp_options`',
        ], $this->statements('RENAME'));
    }

    public function testATableThatIsNotLiveYetIsRenamedWithoutABackupPair(): void
    {
        $this->useTables(['wp_posts', 'pmt_ab12cd_wp_posts', 'pmt_ab12cd_wp_new']);

        $this->swapper->swap($this->map(['wp_posts', 'wp_new']));

        $this->assertSame(
            ['RENAME TABLE `wp_posts` TO `pmb_ab12cd_wp_posts`, `pmt_ab12cd_wp_posts` TO `wp_posts`, `pmt_ab12cd_wp_new` TO `wp_new`'],
            $this->statements('RENAME')
        );
        $this->assertSame(['DROP TABLE IF EXISTS `pmb_ab12cd_wp_posts`'], $this->statements('DROP'));
    }

    public function testBackupTablesAreDroppedRightAfterTheSwap(): void
    {
        $this->useTables(['wp_posts', 'wp_options', 'pmt_ab12cd_wp_posts', 'pmt_ab12cd_wp_options']);

        $this->swapper->swap($this->map(['wp_posts', 'wp_options']));

        $this->assertSame(['DROP TABLE IF EXISTS `pmb_ab12cd_wp_posts`, `pmb_ab12cd_wp_options`'], $this->statements('DROP'));
        $this->assertContains('SET SESSION foreign_key_checks = 0', $this->wpdb->queries);
        $this->assertSame('SET SESSION foreign_key_checks = 1', end($this->wpdb->queries));
    }

    public function testBackupTablesStayWhenTheFlagAsksForThem(): void
    {
        $this->useTables(['wp_posts', 'pmt_ab12cd_wp_posts']);

        $result = $this->swapper->swap($this->map(['wp_posts']), true);

        $this->assertSame(['swapped' => 1, 'backup_kept' => true], $result);
        $this->assertSame([], $this->statements('DROP'));
    }

    public function testLockWaitTimeoutIsSetForTheSwapAndRestored(): void
    {
        $this->useTables(['wp_posts', 'pmt_ab12cd_wp_posts']);

        $this->swapper->swap($this->map(['wp_posts']), true);

        $set = array_values(array_filter($this->wpdb->queries, static function (string $query): bool {
            return str_starts_with($query, 'SET SESSION lock_wait_timeout');
        }));
        $this->assertSame(['SET SESSION lock_wait_timeout = 30', 'SET SESSION lock_wait_timeout = 86400'], $set);
    }

    public function testAMissingTemporaryTableStopsTheSwapBeforeAnyRename(): void
    {
        $this->useTables(['wp_posts', 'wp_options', 'pmt_ab12cd_wp_posts']);

        try {
            $this->swapper->swap($this->map(['wp_posts', 'wp_options']));
            $this->fail('A missing temporary table must stop the swap');
        } catch (Exception $e) {
            $this->assertSame('Temporary tables are missing: pmt_ab12cd_wp_options', $e->getMessage());
        }

        $this->assertSame([], $this->statements('RENAME'));
        $this->assertSame([], $this->statements('DROP'));
    }

    public function testAFailedRenameReportsTheDatabaseErrorAndKeepsEveryTable(): void
    {
        $this->useTables(['wp_posts', 'pmt_ab12cd_wp_posts']);
        $this->wpdb->failOn = 'RENAME TABLE';

        try {
            $this->swapper->swap($this->map(['wp_posts']));
            $this->fail('A failed RENAME must be an error');
        } catch (Exception $e) {
            $this->assertSame('Failed to swap the tables: Simulated failure for: RENAME TABLE', $e->getMessage());
        }

        $this->assertSame([], $this->statements('DROP'));
        $this->assertSame('SET SESSION lock_wait_timeout = 86400', end($this->wpdb->queries));
        $this->assertSame(['pmt_ab12cd_wp_posts', 'wp_posts'], $this->wpdb->tables());
    }

    public function testMissingTemporaryTablesAreListed(): void
    {
        $this->useTables(['pmt_ab12cd_wp_posts']);

        $this->assertSame(['pmt_ab12cd_wp_options'], $this->swapper->missingTemporary($this->map(['wp_posts', 'wp_options'])));
    }

    public function testDropTemporaryOnlyDropsNamesWithTheTemporaryPrefix(): void
    {
        $map = $this->map(['wp_posts', 'wp_options']);
        $map['wp_options']['temp'] = 'wp_options';

        $this->swapper->dropTemporary($map);

        $this->assertSame(['DROP TABLE IF EXISTS `pmt_ab12cd_wp_posts`'], $this->statements('DROP'));
    }

    public function testDropBackupsOnlyDropsNamesWithTheBackupPrefix(): void
    {
        $map = $this->map(['wp_posts', 'wp_options']);
        $map['wp_posts']['backup'] = 'wp_posts';

        $this->swapper->dropBackups($map);

        $this->assertSame(['DROP TABLE IF EXISTS `pmb_ab12cd_wp_options`'], $this->statements('DROP'));
    }

    public function testNothingIsDroppedForAnEmptyMap(): void
    {
        $this->swapper->dropTemporary([]);
        $this->swapper->dropBackups([]);

        $this->assertSame([], $this->wpdb->queries);
    }

    public function testQuoteIdentifierDoublesBackticks(): void
    {
        $this->assertSame('`wp``posts`', TableSwapper::quoteIdentifier('wp`posts'));
    }

    /**
     * @param string[] $names
     */
    private function useTables(array $names): void
    {
        foreach ($names as $name) {
            $this->wpdb->sqlite('CREATE TABLE ' . $name . ' (id INTEGER)');
        }
    }

    /**
     * @param string[] $sourceNames
     * @return array<string, array{destination: string, temp: string, backup: string}>
     */
    private function map(array $sourceNames): array
    {
        return $this->swapper->buildMap('ab12cd', $sourceNames, 'wp_');
    }

    /**
     * @return string[]
     */
    private function statements(string $verb): array
    {
        return array_values(array_filter($this->wpdb->queries, static function (string $query) use ($verb): bool {
            return str_starts_with($query, $verb);
        }));
    }
}
