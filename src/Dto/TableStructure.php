<?php

namespace WordpressMigrator\Dto;

/**
 * Class TableStructure
 *
 * @package WordpressMigrator\Dto
 *
 * Data Transfer Object for table structure information.
 */
class TableStructure
{
    public string $name;
    public string $createStatement;
    /** @var IndexInfo[] */
    public array $indexes;

    /**
     * TableStructure constructor.
     *
     * @param string $name
     * @param string $createStatement
     * @param array  $indexes
     */
    public function __construct(string $name, string $createStatement, array $indexes)
    {
        $this->name = $name;
        $this->createStatement = $createStatement;
        $this->indexes = $indexes;
    }
}
