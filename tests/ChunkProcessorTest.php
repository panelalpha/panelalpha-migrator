<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Restore\ChunkProcessor;
use WordpressMigrator\Restore\FileRestoreHandler;
use WordpressMigrator\Restore\TableRestoreHandler;
use WordpressMigrator\Services\Backup\ChunkService;
use WordpressMigrator\Services\Migration\MigrationRegistry;
use WordpressMigrator\Tests\Support\FakeDatabase;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class ChunkProcessorTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private TableRestoreHandler $tableHandler;

    private FileRestoreHandler $fileHandler;

    private ChunkProcessor $processor;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        FakeDatabase::install();
        $this->tableHandler = $this->createMock(TableRestoreHandler::class);
        $this->fileHandler = $this->createMock(FileRestoreHandler::class);
        $this->processor = new ChunkProcessor($this->tableHandler, $this->fileHandler, new ChunkService());
    }

    public function testStructureChunkGoesToTheTableHandler(): void
    {
        $chunk = ['type' => 'table_structure'];
        $this->tableHandler->expects($this->once())->method('restoreStructure')->with($chunk)->willReturn(['action' => 'a']);

        $this->assertSame(['action' => 'a'], $this->processor->process('mig_1', $chunk));
    }

    public function testDataChunkGoesToTheTableHandler(): void
    {
        $chunk = ['type' => 'table_data'];
        $this->tableHandler->expects($this->once())->method('restoreData')->with($chunk)->willReturn(['action' => 'b']);

        $this->assertSame(['action' => 'b'], $this->processor->process('mig_1', $chunk));
    }

    public function testFileChunkGoesToTheFileHandler(): void
    {
        $chunk = ['type' => 'file_chunk'];
        $this->fileHandler->expects($this->once())->method('restoreChunk')->with($chunk)->willReturn(['action' => 'c']);

        $this->assertSame(['action' => 'c'], $this->processor->process('mig_1', $chunk));
    }

    public function testInfoChunkIsSavedAndRegistered(): void
    {
        $result = $this->processor->process('mig_1', [
            'type' => 'info',
            'files' => [['path' => 'a.txt', 'total_chunks' => 1]],
        ]);

        $this->assertSame(['action' => 'registered_migration'], $result);
        $this->assertFileExists(Config::get('migration_dir') . 'collected/mig_1/migration_info.json');
        $this->assertSame('collecting', (new MigrationRegistry('mig_1'))->getStatus()['status']);
    }

    public function testCompletionMarksTheMigrationRestored(): void
    {
        $this->processor->process('mig_1', ['type' => 'info']);

        $result = $this->processor->process('mig_1', ['type' => 'migration_complete']);

        $this->assertSame('migration_completed', $result['action']);
        $this->assertSame('restored', (new MigrationRegistry('mig_1'))->getStatus()['status']);
    }

    public function testIncompleteManifestBlocksFinalization(): void
    {
        $this->processor->process('mig_1', [
            'type' => 'info',
            'files' => [['path' => 'a.txt', 'total_chunks' => 1]],
        ]);

        try {
            $this->processor->process('mig_1', ['type' => 'migration_complete']);
            $this->fail('An incomplete manifest must not be finalized');
        } catch (Exception $exception) {
            $this->assertSame('Migration manifest is incomplete', $exception->getMessage());
        }

        $this->assertSame('collecting', (new MigrationRegistry('mig_1'))->getStatus()['status']);
    }

    public function testCompletionAcceptsAFileTheSourceSkipped(): void
    {
        $this->registerTwoFiles();
        (new MigrationRegistry('mig_1'))->markChunkRestored('file_chunk', [
            'file' => ['path' => 'a.txt', 'chunk_info' => ['chunk_number' => 1]],
        ]);

        $result = $this->processor->process('mig_1', ['type' => 'migration_complete', 'skipped_files' => ['b.log']]);

        $this->assertSame('migration_completed', $result['action']);
        $this->assertSame(['b.log'], $result['skipped_files']);
        $this->assertNotEmpty($result['warning']);
        $this->assertSame('restored', (new MigrationRegistry('mig_1'))->getStatus()['status']);
    }

    public function testASkippedFileDoesNotLetAnotherMissingFilePass(): void
    {
        $this->registerTwoFiles();

        try {
            $this->processor->process('mig_1', ['type' => 'migration_complete', 'skipped_files' => ['b.log']]);
            $this->fail('a.txt is missing and was not skipped');
        } catch (Exception $exception) {
            $this->assertSame('Migration manifest is incomplete', $exception->getMessage());
        }
    }

    public function testUnknownSkippedPathsAreIgnored(): void
    {
        $this->registerTwoFiles();
        (new MigrationRegistry('mig_1'))->markChunkRestored('file_chunk', [
            'file' => ['path' => 'a.txt', 'chunk_info' => ['chunk_number' => 1]],
        ]);

        $this->expectException(Exception::class);
        $this->processor->process('mig_1', ['type' => 'migration_complete', 'skipped_files' => ['../etc/passwd', 'nope.txt']]);
    }

    public function testUnknownSkippedPathsAreNotReported(): void
    {
        $this->processor->process('mig_1', ['type' => 'info']);

        $result = $this->processor->process('mig_1', ['type' => 'migration_complete', 'skipped_files' => ['nope.txt']]);

        $this->assertArrayNotHasKey('skipped_files', $result);
        $this->assertArrayNotHasKey('warning', $result);
    }

    public function testBadSkippedFilesPayloadIsIgnored(): void
    {
        $this->registerTwoFiles();

        foreach (['b.log', ['a' => ['b.log']], [123, null, ['b.log']], 5] as $payload) {
            try {
                $this->processor->process('mig_1', ['type' => 'migration_complete', 'skipped_files' => $payload]);
                $this->fail('A bad payload must not complete the migration');
            } catch (Exception $exception) {
                $this->assertSame('Migration manifest is incomplete', $exception->getMessage());
            }
        }
    }

    public function testFinalizationRetryAfterSuccessIsIdempotent(): void
    {
        $this->processor->process('mig_1', [
            'type' => 'info',
            'files' => [['path' => 'a.txt', 'total_chunks' => 1]],
        ]);
        $registry = new MigrationRegistry('mig_1');
        $registry->markChunkRestored('file_chunk', [
            'file' => ['path' => 'a.txt', 'chunk_info' => ['chunk_number' => 1]],
        ]);

        $first = $this->processor->process('mig_1', ['type' => 'migration_complete']);
        $second = $this->processor->process('mig_1', ['type' => 'migration_complete']);

        $this->assertSame('migration_completed', $first['action']);
        $this->assertSame('migration_completed', $second['action']);
        $this->assertSame('restored', $registry->getStatus()['status']);
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown chunk type: bogus');

        $this->processor->process('mig_1', ['type' => 'bogus']);
    }

    private function registerTwoFiles(): void
    {
        $this->processor->process('mig_1', [
            'type' => 'info',
            'files' => [
                ['path' => 'a.txt', 'total_chunks' => 1],
                ['path' => 'b.log', 'total_chunks' => 2],
            ],
        ]);
    }
}
