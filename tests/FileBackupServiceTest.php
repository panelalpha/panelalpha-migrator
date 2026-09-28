<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Services\Backup\FileBackupService;
use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class FileBackupServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $siteDir;

    private FakeDestination $destination;

    private MigrationStorageService $storage;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $this->siteDir = $this->createTemporaryDirectory();
        Config::set('root_path', $this->siteDir);
        $this->destination = FakeDestination::get();
        $this->storage = new MigrationStorageService();
    }

    public function testConstructorThrowsWhenMigrationFileIsMissing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Migration file not found');

        new FileBackupService('missing_mig');
    }

    public function testConstructorThrowsWhenDestinationUrlIsMissing(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'token' => 'secret',
            'files' => [],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination URL not found in migration data');

        new FileBackupService('mig_1');
    }

    public function testDestinationUrlIsReadFromCamelCaseKey(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertCount(1, $this->destination->requests());
    }

    public function testDestinationUrlIsReadFromSnakeCaseKey(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destination_url' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertCount(1, $this->destination->requests());
    }

    public function testTokenComesFromMigrationData(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'from-migration',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame('from-migration', $this->destination->requests()[0]['headers']['x-migrator-token']);
    }

    #[RunInSeparateProcess]
    public function testTokenFallsBackToConfigPhpWhenMissingFromMigrationData(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'config.php', "<?php return ['token' => 'config-token'];");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame('config-token', $this->destination->requests()[0]['headers']['x-migrator-token']);
    }

    public function testSinglePendingChunkIsSentAndMarkedSent(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello world');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello world', 11)],
        ]);

        $result = (new FileBackupService('mig_1'))->getNextChunk();

        $request = $this->destination->requests()[0];
        $this->assertSame('/wp-content/plugins/wordpress-migrator/restore.php?action=collect-and-restore', $request['uri']);
        $this->assertSame('secret', $request['headers']['x-migrator-token']);

        $body = JsonCompressor::decompressFromJson($request['body']);
        $this->assertSame('file_chunk', $body['type']);
        $this->assertSame('mig_1', $body['migration_id']);
        $this->assertSame('file_chunk_00001', $body['chunk_id']);
        $this->assertSame('a.txt', $body['file']['path']);
        $this->assertSame(base64_encode('hello world'), $body['content']);
        $this->assertSame(hash('sha256', 'hello world'), $body['checksum']);
        $this->assertSame([
            'chunk_number' => 1,
            'total_chunks' => 1,
            'offset' => 0,
            'size' => 11,
        ], $body['file']['chunk_info']);

        $this->assertArrayNotHasKey('content', $result);
        $this->assertSame('file_chunk_00001', $result['chunk_id']);

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('sent', $saved['files'][0]['status']);
        $this->assertSame('sent', $saved['files'][0]['chunks'][0]['status']);
    }

    public function testMultiChunkFileGoesInProgressThenSentWithBytesAtTheRightOffsets(): void
    {
        $content = '0123456789';
        self::writeFile($this->siteDir . 'big.bin', $content);
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('big.bin', $content, 6)],
        ]);
        $service = new FileBackupService('mig_1');

        $service->getNextChunk();

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('in_progress', $saved['files'][0]['status']);
        $this->assertSame('sent', $saved['files'][0]['chunks'][0]['status']);
        $this->assertSame('pending', $saved['files'][0]['chunks'][1]['status']);
        $firstBody = JsonCompressor::decompressFromJson($this->destination->requests()[0]['body']);
        $this->assertSame(base64_encode('012345'), $firstBody['content']);
        $this->assertSame('file_chunk_00001', $firstBody['chunk_id']);

        $service->getNextChunk();

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('sent', $saved['files'][0]['status']);
        $this->assertSame('sent', $saved['files'][0]['chunks'][1]['status']);
        $secondBody = JsonCompressor::decompressFromJson($this->destination->requests()[1]['body']);
        $this->assertSame(base64_encode('6789'), $secondBody['content']);
        $this->assertSame('file_chunk_00002', $secondBody['chunk_id']);
    }

    public function testReturnsNullWhenNothingIsPending(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hi');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hi', 2)],
        ]);
        $service = new FileBackupService('mig_1');

        $service->getNextChunk();

        $this->assertNull($service->getNextChunk());
    }

    public function testBase64ModeSendsOctetStream(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'base64' => true,
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $request = $this->destination->requests()[0];
        $this->assertSame('application/octet-stream', $request['headers']['content-type']);
        $this->assertSame('base64', $request['headers']['x-migrator-encoding']);
        $decoded = base64_decode($request['body'], true);
        $body = JsonCompressor::decompressFromJson($decoded);
        $this->assertSame(base64_encode('hello'), $body['content']);
    }

    public function testDeletedFileIsSkippedAndTheNextFileContinues(): void
    {
        self::writeFile($this->siteDir . 'present.txt', 'hi');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [
                $this->fileEntry('missing.txt', 'xxxxx', 5),
                $this->fileEntry('present.txt', 'hi', 2),
            ],
        ]);

        $result = (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame('present.txt', $result['file']['path']);
        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('skipped', $saved['files'][0]['status']);
        $this->assertSame('skipped', $saved['files'][0]['chunks'][0]['status']);
        $this->assertSame('sent', $saved['files'][1]['chunks'][0]['status']);
    }

    public function testHttpErrorThrowsAndLeavesTheChunkPending(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);
        $this->destination->respondWith('restore.php', 500, '{"error":"boom"}');

        $service = new FileBackupService('mig_1');

        try {
            $service->getNextChunk();
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Destination returned error: 500', $e->getMessage());
        }

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('pending', $saved['files'][0]['status']);
        $this->assertSame('pending', $saved['files'][0]['chunks'][0]['status']);
    }

    public function testProgressIncludesPartiallySentInProgressFile(): void
    {
        $fileA = $this->fileEntry('a.txt', '0123456789', 10);
        $fileA['status'] = 'sent';
        $fileA['chunks'][0]['status'] = 'sent';

        $fileB = $this->fileEntry('b.txt', '0123456789', 5);
        $fileB['status'] = 'in_progress';
        $fileB['chunks'][0]['status'] = 'sent';

        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$fileA, $fileB],
        ]);

        $progress = (new FileBackupService('mig_1'))->getProgress();

        $this->assertSame(3, $progress['total_chunks']);
        $this->assertSame(2, $progress['sent_chunks']);
        $this->assertSame(2, $progress['total_files']);
        $this->assertSame(1, $progress['completed_files']);
        $this->assertEqualsWithDelta(66.67, $progress['percentage'], 0.01);
        $this->assertFalse($progress['is_complete']);
        $this->assertSame('b.txt', $progress['current_file']);
        $this->assertSame(20, $progress['total_size']);
        $this->assertSame(15, $progress['sent_size']);
    }

    public function testProgressCurrentFileCanBeOverridden(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hi', 2)],
        ]);

        $progress = (new FileBackupService('mig_1'))->getProgress('custom/override.txt');

        $this->assertSame('custom/override.txt', $progress['current_file']);
    }

    private function fileEntry(string $path, string $content, int $chunkSize, string $status = 'pending'): array
    {
        $size = strlen($content);
        $chunks = [];
        $offset = 0;
        $id = 1;

        while ($offset < $size) {
            $length = min($chunkSize, $size - $offset);
            $chunks[] = ['id' => $id, 'offset' => $offset, 'size' => $length, 'status' => 'pending'];
            $offset += $length;
            $id++;
        }

        if ($chunks === []) {
            $chunks[] = ['id' => 1, 'offset' => 0, 'size' => 0, 'status' => 'pending'];
        }

        return [
            'path' => $path,
            'size' => $size,
            'permissions' => '644',
            'modified' => '2024-01-01T00:00:00+00:00',
            'chunks' => $chunks,
            'totalChunks' => count($chunks),
            'status' => $status,
        ];
    }
}
