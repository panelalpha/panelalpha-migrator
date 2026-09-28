<?php

namespace WordpressMigrator\Dto;

/**
 * Class IndexInfo
 *
 * @package WordpressMigrator\Dto
 *
 * Data Transfer Object for database index information.
 */
class IndexInfo
{
    public string $name;
    /** @var string[] */
    public array $columns;
    public string $type;

    /**
     * IndexInfo constructor.
     *
     * @param string $name
     * @param array  $columns
     * @param string $type
     */
    public function __construct(string $name, array $columns, string $type)
    {
        $this->name = $name;
        $this->columns = $columns;
        $this->type = $type;
    }
}
