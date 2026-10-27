<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

use Exception;

/**
 * The destination URL or manual IP points at a blocked address.
 */
class DestinationNotAllowedException extends Exception
{
    public function __construct()
    {
        parent::__construct('The destination address is not allowed.');
    }
}
