<?php

namespace PanelAlpha\Migrator\Dto;

/**
 * Class ChunkInfo
 *
 * @package PanelAlpha\Migrator\Dto
 *
 * Data Transfer Object for chunk information.
 */
class ChunkInfo
{
    public int $id;
    public int $offset;
    public int $limit;
    public string $status;

    /**
     * ChunkInfo constructor.
     *
     * @param int    $id
     * @param int    $offset
     * @param int    $limit
     * @param string $status
     */
    public function __construct(int $id, int $offset, int $limit, string $status = 'pending')
    {
        $this->id = $id;
        $this->offset = $offset;
        $this->limit = $limit;
        $this->status = $status;
    }
}
