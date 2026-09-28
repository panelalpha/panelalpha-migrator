<?php

namespace WordpressMigrator\Dto;

/**
 * Class TableInfo
 *
 * @package WordpressMigrator\Dto
 *
 * Data Transfer Object for table information.
 */
class TableInfo
{
    public string $name;
    public int $size;
    public int $rowCount;
    public bool $structureSent;
    /** @var ChunkInfo[] */
    public array $chunks;
    public int $totalChunks;
    public string $status;

    /**
     * TableInfo constructor.
     *
     * @param string $name
     * @param int    $size
     * @param int    $rowCount
     * @param array  $chunks
     * @param int    $totalChunks
     * @param bool   $structureSent
     * @param string $status
     */
    public function __construct(
        string $name,
        int $size,
        int $rowCount,
        array $chunks,
        int $totalChunks,
        bool $structureSent = false,
        string $status = 'pending'
    ) {
        $this->name = $name;
        $this->size = $size;
        $this->rowCount = $rowCount;
        $this->chunks = $chunks;
        $this->totalChunks = $totalChunks;
        $this->structureSent = $structureSent;
        $this->status = $status;
    }
}
