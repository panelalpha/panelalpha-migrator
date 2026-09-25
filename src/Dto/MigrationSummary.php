<?php

namespace WordpressMigrator\Dto;

/**
 * Class MigrationSummary
 *
 * @package WordpressMigrator\Dto
 *
 * Data Transfer Object for migration summary.
 */
class MigrationSummary
{
    public int $totalFiles;
    public int $totalFileSize;
    public int $totalTables;
    public int $totalDbSize;
    public int $totalRows;
    public int $estimatedChunks;
    public int $fileChunks;
    public int $dbChunks;

    /**
     * MigrationSummary constructor.
     *
     * @param int $totalFiles
     * @param int $totalFileSize
     * @param int $totalTables
     * @param int $totalDbSize
     * @param int $totalRows
     * @param int $estimatedChunks
     * @param int $fileChunks
     * @param int $dbChunks
     */
    public function __construct(
        int $totalFiles,
        int $totalFileSize,
        int $totalTables,
        int $totalDbSize,
        int $totalRows,
        int $estimatedChunks,
        int $fileChunks,
        int $dbChunks
    ) {
        $this->totalFiles = $totalFiles;
        $this->totalFileSize = $totalFileSize;
        $this->totalTables = $totalTables;
        $this->totalDbSize = $totalDbSize;
        $this->totalRows = $totalRows;
        $this->estimatedChunks = $estimatedChunks;
        $this->fileChunks = $fileChunks;
        $this->dbChunks = $dbChunks;
    }
}
