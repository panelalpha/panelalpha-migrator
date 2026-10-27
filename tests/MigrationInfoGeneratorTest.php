<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Dto\ChunkInfo;
use WordpressMigrator\Dto\FileChunkInfo;
use WordpressMigrator\Dto\FileInfo;
use WordpressMigrator\Dto\TableInfo;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\Database;
use WordpressMigrator\Services\Migration\MigrationInfoGenerator;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class MigrationInfoGeneratorTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    protected function setUp(): void
    {
        FakeDatabase::install(new MigrationInfoDatabase());
        Config::set('wp_version', '6.8.3');
        Config::set('site_url', 'https://old.example.com');
        Config::set('home_url', 'https://old.example.com/blog');
    }

    public function testGeneratesMigrationWithAccurateTotalsAndSourceMetadata(): void
    {
        $GLOBALS['table_prefix'] = 'custom_';
        $files = [
            new FileInfo('a.txt', 5, '644', 'now', [new FileChunkInfo(1, 0, 5)], 1),
            new FileInfo('b.bin', 8, '644', 'now', [new FileChunkInfo(1, 0, 4), new FileChunkInfo(2, 4, 4)], 2),
        ];
        $tables = [new TableInfo('custom_posts', 400, 101, [new ChunkInfo(1, 0, 100), new ChunkInfo(2, 100, 100)], 2)];

        $migration = (new MigrationInfoGenerator())->generate($files, $tables, 'https://new.example.com');

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
        $generator = new MigrationInfoGenerator();

        $first = $generator->generate([], [], 'https://new.example.com')->migrationId;
        $second = $generator->generate([], [], 'https://new.example.com')->migrationId;

        $this->assertNotSame($first, $second);
    }

    public function testEmptyMigrationAndPrefixFromWpConfig(): void
    {
        $root = $this->createTemporaryDirectory();
        self::writeFile($root . 'wp-config.php', "<?php\ndefine('DB_NAME', 'test');\ndefine('DB_USER', 'test');\ndefine('DB_HOST', 'localhost');\n\$table_prefix = 'parsed_';\n");
        Config::set('root_path', $root);
        unset($GLOBALS['table_prefix']);

        $migration = (new MigrationInfoGenerator())->generate([], [], 'https://new.example.com');

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
        self::assertSame('parsed_', $migration->sourceTablePrefix);
    }

    public function testFormatsSizes(): void
    {
        self::assertSame('0 B', MigrationInfoGenerator::formatSize(0));
        self::assertSame('1 KB', MigrationInfoGenerator::formatSize(1024));
        self::assertSame('1.5 MB', MigrationInfoGenerator::formatSize(1572864));
        self::assertSame('0 B', MigrationInfoGenerator::formatSize(-1));
    }
}

final class MigrationInfoDatabase extends Database
{
    public function __construct() {}

    public function getVar(string $query, array $params = [])
    {
        if ($query !== 'SELECT VERSION()') {
            throw new \LogicException('Unexpected query: ' . $query);
        }
        return '8.0.36';
    }
}
