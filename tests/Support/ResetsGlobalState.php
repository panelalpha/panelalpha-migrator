<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use ReflectionClass;
use WordpressMigrator\Helpers\Config;

/**
 * Clears the plugin's static state between tests: Config, the Database singleton and $table_prefix.
 */
trait ResetsGlobalState
{
    /**
     * @after
     */
    #[After]
    protected function resetGlobalState(): void
    {
        (new ReflectionClass(Config::class))->setStaticPropertyValue('config', []);
        FakeDatabase::reset();
        unset($GLOBALS['table_prefix']);
    }
}
