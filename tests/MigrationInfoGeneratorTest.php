<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Dto\ChunkInfo;
use PanelAlpha\Migrator\Dto\FileChunkInfo;
use PanelAlpha\Migrator\Dto\FileInfo;
use PanelAlpha\Migrator\Dto\TableInfo;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Services\Migration\MigrationInfoGenerator;
use PanelAlpha\Migrator\Tests\Support\RecordingWpdb;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class MigrationInfoGeneratorTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private RecordingWpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new RecordingWpdb();
        $this->wpdb->varAnswers['VERSION()'] = '8.0.36';
        Config::set('wp_version', '6.8.3');
        Config::set('site_url', 'https://old.example.com');
        Config::set('home_url', 'https://old.example.com/blog');
    }

    public function testGeneratesMigrationWithAccurateTotalsAndSourceMetadata(): void
    {
        $this->wpdb->base_prefix = 'custom_';
        $files = [
            new FileInfo('a.txt', 5, '644', 'now', [new FileChunkInfo(1, 0, 5)], 1),
            new FileInfo('b.bin', 8, '644', 'now', [new FileChunkInfo(1, 0, 4), new FileChunkInfo(2, 4, 4)], 2),
        ];
        $tables = [new TableInfo('custom_posts', 400, 101, [new ChunkInfo(1, 0, 100), new ChunkInfo(2, 100, 100)], 2)];

        $migration = (new MigrationInfoGenerator($this->wpdb))->generate($files, $tables, 'https://new.example.com');

        self::assertMatchesRegularExpression('/^mig_\d{8}_\d{6}_[a-f0-9]{32}$/', $migration->migrationId);
        self::assertLessThanOrEqual(2, abs(time() - strtotime($migration->timestamp)));
        self::assertSame('https://new.example.com', $migration->destinationUrl);
        self::assertSame('6.8.3', $migration->wordpressVersion);
        self::assertSame(PHP_VERSION, $migration->phpVersion);
        self::assertSame('8.0.36', $migration->mysqlVersion);
        self::assertSame('https://old.example.com', $migration->siteUrl);
        self::assertSame('https://old.example.com/blog', $migration->homeUrl);
        self::assertSame('custom_', $migration->sourceTablePrefix);
        self::assertSame($files, $migration->files);
        self::assertSame($tables, $migration->tables);
        self::assertSame([2, 13, 1, 400, 101, 5, 3, 2], [
            $migration->summary->totalFiles,
            $migration->summary->totalFileSize,
            $migration->summary->totalTables,
            $migration->summary->totalDbSize,
            $migration->summary->totalRows,
            $migration->summary->estimatedChunks,
            $migration->summary->fileChunks,
            $migration->summary->dbChunks,
        ]);
    }

    public function testWithoutSecretsRemovesEveryTokenKey(): void
    {
        $info = ['migration_id' => 'mig_1', 'token' => 'a', 't' => 'b', 'sourceToken' => 'c', 'source_token' => 'd', 'stk' => 'e'];

        self::assertSame(['migration_id' => 'mig_1', 't' => 'b'], MigrationInfoGenerator::withoutSecrets($info));
    }

    public function testTwoGenerationsInTheSameSecondDoNotShareAnId(): void
    {
        $generator = new MigrationInfoGenerator($this->wpdb);

        $first = $generator->generate([], [], 'https://new.example.com')->migrationId;
        $second = $generator->generate([], [], 'https://new.example.com')->migrationId;

        $this->assertNotSame($first, $second);
    }

    public function testEmptyMigrationAndDefaultPrefix(): void
    {
        $migration = (new MigrationInfoGenerator($this->wpdb))->generate([], [], 'https://new.example.com');

        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0], [
            $migration->summary->totalFiles,
            $migration->summary->totalFileSize,
            $migration->summary->totalTables,
            $migration->summary->totalDbSize,
            $migration->summary->totalRows,
            $migration->summary->estimatedChunks,
            $migration->summary->fileChunks,
            $migration->summary->dbChunks,
        ]);
        self::assertSame('wp_', $migration->sourceTablePrefix);
    }

    public function testFormatsSizes(): void
    {
        self::assertSame('0 B', MigrationInfoGenerator::formatSize(0));
        self::assertSame('1 KB', MigrationInfoGenerator::formatSize(1024));
        self::assertSame('1.5 MB', MigrationInfoGenerator::formatSize(1572864));
        self::assertSame('0 B', MigrationInfoGenerator::formatSize(-1));
    }
}
