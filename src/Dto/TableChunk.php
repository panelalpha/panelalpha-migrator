<?php

namespace WordpressMigrator\Dto;

/**
 * Class TableChunk
 *
 * @package WordpressMigrator\Dto
 *
 * Data Transfer Object for a chunk of table data.
 */
class TableChunk
{
    /** @var string[] */
    public array $columns;
    public array $data;
    public int $actualRows;

    /**
     * TableChunk constructor.
     *
     * @param array $columns
     * @param array $data
     * @param int   $actualRows
     */
    public function __construct(array $columns, array $data, int $actualRows)
    {
        $this->columns = $columns;
        $this->data = $data;
        $this->actualRows = $actualRows;
    }
}
