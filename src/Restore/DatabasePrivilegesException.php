<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use Exception;

/**
 * The destination database user cannot create, rename or drop tables.
 */
class DatabasePrivilegesException extends Exception
{
    public function __construct()
    {
        parent::__construct('Destination database user cannot create or rename tables (needs CREATE, ALTER, DROP)');
    }
}
