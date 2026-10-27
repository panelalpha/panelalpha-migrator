<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Services\Backup\ChunkService;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Services\Restore\RestoreService;
use WordpressMigrator\Services\Scanner\DatabaseScanner;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\RecordingPdo;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class RestoreServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private ChunkService $chunks;
    private RestoreService $restore;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        FakeDatabase::install();
        $this->chunks = new ChunkService();
        $this->restore = new RestoreService();
    }

    public function testProgressReportsMissingMigration(): void
    {
        $this->assertSame(['status' => 'not_found', 'percentage' => 0], $this->restore->getProgress('missing'));
    }

    public function testProgressReflectsReceivedAndRestoredChunksFromCollectedData(): void
    {
        $info = [
            'type' => 'info',
            'migration_id' => 'mig_1',
            'summary' => ['estimated_chunks' => 2],
            'files' => [
                ['path' => 'one.txt', 'total_chunks' => 1],
                ['path' => 'two.txt', 'total_chunks' => 1],
            ],
            'tables' => [],
        ];
        $first = ['type' => 'file_chunk', 'migration_id' => 'mig_1', 'chunk_id' => 'one_1', 'file' => ['path' => 'one.txt']];
        $second = ['type' => 'file_chunk', 'migration_id' => 'mig_1', 'chunk_id' => 'two_1', 'file' => ['path' => 'two.txt']];

        $this->chunks->saveChunk('mig_1', $info);
        $this->chunks->registerChunk('mig_1', $info);
        $initial = $this->restore->getProgress('mig_1');
        $this->assertSame('collecting', $initial['status']);
        $this->assertSame(2, $initial['total_files']);
        $this->assertSame(0, $initial['chunks_received']);
        $this->assertSame(2, $initial['chunks_total']);
        $this->assertSame(0.0, $initial['percentage']);

        $this->chunks->saveChunk('mig_1', $first);
        $this->chunks->registerChunk('mig_1', $first);
        $this->chunks->saveChunk('mig_1', $second);
        $this->chunks->registerChunk('mig_1', $second);
        $this->assertSame(2, $this->restore->getProgress('mig_1')['chunks_received']);

        $registry = new MigrationRegistry('mig_1');
        $registry->updateStatus('restoring');
        $registry->markChunkRestored('file_chunk', $first);

        $halfway = $this->restore->getProgress('mig_1');
        $this->assertSame('restoring', $halfway['status']);
        $this->assertSame(1, $halfway['restored_files']);
        $this->assertSame(50.0, $halfway['percentage']);
        $this->assertNotNull($halfway['started_at']);

        $registry->markChunkRestored('file_chunk', $second);
        $registry->updateStatus('restored');
        $complete = $this->restore->getProgress('mig_1');
        $this->assertSame('restored', $complete['status']);
        $this->assertSame(2, $complete['restored_files']);
        $this->assertSame(100.0, $complete['percentage']);
        $this->assertNotNull($complete['completed_at']);
    }

    public function testMissingMigrationHasNoChunkToRestore(): void
    {
        $this->assertNull($this->restore->restoreFileNextChunk('missing'));
        $this->assertNull($this->restore->restoreDatabaseNextChunk('missing'));
    }

    public function testStoredFileChunksAreRestoredInOrder(): void
    {
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        $this->registerCollected('mig_1', [['path' => 'hello.txt', 'total_chunks' => 2]], []);

        $second = $this->fileChunk('hello.txt', 'cd', 2, 2, 'file_chunk_00002', 2);
        $first = $this->fileChunk('hello.txt', 'ab', 1, 2, 'file_chunk_00001', 0);
        $this->chunks->saveChunk('mig_1', $second);
        $this->chunks->registerChunk('mig_1', $second);
        $this->chunks->saveChunk('mig_1', $first);
        $this->chunks->registerChunk('mig_1', $first);

        $restored = $this->restore->restoreFileNextChunk('mig_1');
        $this->assertSame(['action' => 'restored_file_chunk', 'file' => 'hello.txt', 'chunk' => 1], $restored);
        $this->assertSame('ab', file_get_contents($root . 'hello.txt'));

        $restored = $this->restore->restoreFileNextChunk('mig_1');
        $this->assertSame(2, $restored['chunk']);
        $this->assertSame('abcd', file_get_contents($root . 'hello.txt'));
        $this->assertNull($this->restore->restoreFileNextChunk('mig_1'));
    }

    public function testStoredDatabaseChunksRestoreStructureBeforeRows(): void
    {
        $pdo = new RecordingPdo();
        FakeDatabase::install($pdo);
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        file_put_contents($root . 'wp-config.php', "<?php\ndefine('DB_NAME', 'db');\ndefine('DB_USER', 'user');\ndefine('DB_HOST', 'db.internal');\n\$table_prefix = 'wp_';\n");

        $this->registerCollected('mig_1', [], [['name' => 'wp_posts', 'total_chunks' => 1]]);
        $rows = [[1, 'Hello']];
        $data = [
            'type' => 'table_data',
            'chunk_id' => 'table_chunk_00001',
            'table' => ['name' => 'wp_posts', 'chunk_info' => ['chunk_number' => 1, 'total_chunks' => 1]],
            'columns' => ['ID', 'post_title'],
            'data' => $rows,
            'checksum' => $this->checksum($rows),
            'source_table_prefix' => 'wp_',
        ];
        $structure = [
            'type' => 'table_structure',
            'table' => [
                'name' => 'wp_posts',
                'create_statement' => 'CREATE TABLE `wp_posts` (`ID` bigint, `post_title` text)',
                'indexes' => [],
            ],
            'source_table_prefix' => 'wp_',
        ];
        $this->chunks->saveChunk('mig_1', $data);
        $this->chunks->registerChunk('mig_1', $data);
        $this->chunks->saveChunk('mig_1', $structure);
        $this->chunks->registerChunk('mig_1', $structure);

        $this->assertSame('restored_table_structure', $this->restore->restoreDatabaseNextChunk('mig_1')['action']);
        $this->assertContains('DROP TABLE IF EXISTS `wp_posts`', $pdo->statements);
        $pdo->sqlite('CREATE TABLE wp_posts (ID INTEGER, post_title TEXT)');
        $restored = $this->restore->restoreDatabaseNextChunk('mig_1');
        $this->assertSame(['action' => 'restored_table_data', 'table' => 'wp_posts', 'chunk' => 1], $restored);
        $this->assertEquals([[1, 'Hello']], $pdo->query('SELECT * FROM wp_posts')->fetchAll(\PDO::FETCH_NUM));
        $this->assertNull($this->restore->restoreDatabaseNextChunk('mig_1'));
    }

    public function testChunkOneOfTwoTablesBothSurviveAndARetryDoesNotDuplicateRows(): void
    {
        $pdo = new RecordingPdo();
        FakeDatabase::install($pdo);
        $root = $this->createTemporaryDirectory();
        Config::set('root_path', $root);
        file_put_contents($root . 'wp-config.php', "<?php\ndefine('DB_NAME', 'db');\ndefine('DB_USER', 'user');\ndefine('DB_HOST', 'db.internal');\n\$table_prefix = 'wp_';\n");

        $this->registerCollected('mig_1', [], [
            ['name' => 'wp_posts', 'total_chunks' => 1],
            ['name' => 'wp_users', 'total_chunks' => 1],
        ]);
        $posts = $this->tableData('wp_posts', [[1, 'Hello']]);
        $users = $this->tableData('wp_users', [[7, 'Ada']]);
        foreach ([$posts, $users] as $chunk) {
            $this->chunks->saveChunk('mig_1', $chunk);
            $this->chunks->registerChunk('mig_1', $chunk);
        }
        foreach (['wp_posts', 'wp_users'] as $table) {
            $this->chunks->saveChunk('mig_1', [
                'type' => 'table_structure',
                'table' => [
                    'name' => $table,
                    'create_statement' => 'CREATE TABLE `' . $table . '` (`ID` bigint, `title` text)',
                    'indexes' => [],
                ],
                'source_table_prefix' => 'wp_',
            ]);
            $this->chunks->registerChunk('mig_1', [
                'type' => 'table_structure',
                'table' => ['name' => $table],
            ]);
        }

        $actions = [];
        while ($next = $this->restore->restoreDatabaseNextChunk('mig_1')) {
            if ($next['action'] === 'restored_table_structure') {
                $pdo->sqlite('CREATE TABLE ' . $next['table'] . ' (ID INTEGER PRIMARY KEY, title TEXT)');
            }
            $actions[] = $next['action'] . ':' . ($next['table'] ?? '');
        }

        $this->assertContains('restored_table_data:wp_posts', $actions);
        $this->assertContains('restored_table_data:wp_users', $actions);
        $this->assertEquals([[1, 'Hello']], $pdo->query('SELECT * FROM wp_posts')->fetchAll(\PDO::FETCH_NUM));
        $this->assertEquals([[7, 'Ada']], $pdo->query('SELECT * FROM wp_users')->fetchAll(\PDO::FETCH_NUM));

        $registry = new MigrationRegistry('mig_1');
        $handler = new \WordpressMigrator\Restore\TableRestoreHandler(FakeDatabase::install($pdo), $registry);
        $handler->restoreData($posts);
        $handler->restoreData($users);

        $this->assertEquals([[1, 'Hello']], $pdo->query('SELECT * FROM wp_posts')->fetchAll(\PDO::FETCH_NUM));
        $this->assertEquals([[7, 'Ada']], $pdo->query('SELECT * FROM wp_users')->fetchAll(\PDO::FETCH_NUM));
        $this->assertSame(1, $registry->getStatus()['tables']['wp_posts']['restored_chunks']);
        $this->assertSame(1, $registry->getStatus()['tables']['wp_users']['restored_chunks']);
    }

    private function registerCollected(string $migrationId, array $files, array $tables): void
    {
        $info = [
            'type' => 'info',
            'migration_id' => $migrationId,
            'files' => $files,
            'tables' => $tables,
        ];
        $this->chunks->saveChunk($migrationId, $info);
        $this->chunks->registerChunk($migrationId, $info);
    }

    private function fileChunk(string $path, string $content, int $number, int $total, string $chunkId, int $offset = 0): array
    {
        return [
            'type' => 'file_chunk',
            'chunk_id' => $chunkId,
            'file' => [
                'path' => $path,
                'permissions' => '644',
                'modified' => '2024-02-03T04:05:06+00:00',
                'chunk_info' => [
                    'chunk_number' => $number,
                    'total_chunks' => $total,
                    'offset' => $offset,
                    'size' => strlen($content),
                ],
            ],
            'content' => base64_encode($content),
            'checksum' => hash('sha256', $content),
        ];
    }

    private function tableData(string $table, array $rows): array
    {
        return [
            'type' => 'table_data',
            'migration_id' => 'mig_1',
            'chunk_id' => 'table_chunk_00001',
            'table' => ['name' => $table, 'chunk_info' => ['chunk_number' => 1, 'total_chunks' => 1]],
            'columns' => ['ID', 'title'],
            'data' => $rows,
            'checksum' => $this->checksum($rows),
            'source_table_prefix' => 'wp_',
        ];
    }

    private function checksum(array $rows): string
    {
        $scanner = (new \ReflectionClass(DatabaseScanner::class))->newInstanceWithoutConstructor();

        return $scanner->getChecksum($rows);
    }
}
