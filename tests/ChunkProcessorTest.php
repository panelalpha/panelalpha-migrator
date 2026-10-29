<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Restore\ChunkProcessor;
use PanelAlpha\Migrator\Restore\FileRestoreHandler;
use PanelAlpha\Migrator\Restore\TableRestoreHandler;
use PanelAlpha\Migrator\Services\Backup\ChunkService;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

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

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown chunk type: bogus');

        $this->processor->process('mig_1', ['type' => 'bogus']);
    }
}
