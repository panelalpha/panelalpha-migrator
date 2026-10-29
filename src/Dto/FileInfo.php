<?php

namespace PanelAlpha\Migrator\Dto;

/**
 * Class FileInfo
 *
 * @package PanelAlpha\Migrator\Dto
 *
 * Data Transfer Object for file information.
 */
class FileInfo
{
    public string $path;
    public int $size;
    public string $permissions;
    public string $modified;
    /** @var FileChunkInfo[] */
    public array $chunks;
    public int $totalChunks;
    public string $status;

    /**
     * FileInfo constructor.
     *
     * @param string $path
     * @param int    $size
     * @param string $permissions
     * @param string $modified
     * @param array  $chunks
     * @param int    $totalChunks
     * @param string $status
     */
    public function __construct(
        string $path,
        int $size,
        string $permissions,
        string $modified,
        array $chunks,
        int $totalChunks,
        string $status = 'pending'
    ) {
        $this->path = $path;
        $this->size = $size;
        $this->permissions = $permissions;
        $this->modified = $modified;
        $this->chunks = $chunks;
        $this->totalChunks = $totalChunks;
        $this->status = $status;
    }
}
