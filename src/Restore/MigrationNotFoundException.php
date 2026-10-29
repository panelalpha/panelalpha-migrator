<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use Exception;

/**
 * The destination has no migration with this id.
 */
class MigrationNotFoundException extends Exception
{
}
