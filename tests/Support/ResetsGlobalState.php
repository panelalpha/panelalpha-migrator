<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use ReflectionClass;
use PanelAlpha\Migrator\Helpers\Config;

/**
 * Clears the plugin's static state between tests: Config, $table_prefix and $wpdb.
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
        unset($GLOBALS['table_prefix'], $GLOBALS['wpdb']);
    }
}
