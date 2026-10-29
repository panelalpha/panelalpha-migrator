<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use Exception;

/**
 * A table chunk arrived before the info chunk, or for a table the migration does not know.
 */
class MigrationConflictException extends Exception
{
}
