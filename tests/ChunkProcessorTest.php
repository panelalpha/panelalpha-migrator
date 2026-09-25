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

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown chunk type: bogus');

        $this->processor->process('mig_1', ['type' => 'bogus']);
    }
}
