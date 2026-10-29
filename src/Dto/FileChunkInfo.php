<?php

namespace PanelAlpha\Migrator\Dto;

/**
 * Class FileChunkInfo
 *
 * @package PanelAlpha\Migrator\Dto
 *
 * Data Transfer Object for file chunk information.
 */
class FileChunkInfo
{
    public int $id;
    public int $offset;
    public int $size;
    public string $status;

    /**
     * FileChunkInfo constructor.
     *
     * @param int    $id
     * @param int    $offset
     * @param int    $size
     * @param string $status
     */
    public function __construct(int $id, int $offset, int $size, string $status = 'pending')
    {
        $this->id = $id;
        $this->offset = $offset;
        $this->size = $size;
        $this->status = $status;
    }
}
