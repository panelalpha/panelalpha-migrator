<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use Exception;

/**
 * The destination does not have enough free disk space for the files of the migration.
 */
class InsufficientDiskSpaceException extends Exception
{
}
