<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Log;

/**
 * Each test runs in its own process, because a constant cannot be undefined.
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
final class LogTest extends TestCase
{
    public function testItWritesNothingWithoutWpDebug(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pa-log');
        ini_set('error_log', $file);

        Log::error('hidden message');

        $this->assertSame('', (string) file_get_contents($file));
        unlink($file);
    }

    public function testItWritesTheMessageWhenWpDebugIsOn(): void
    {
        define('WP_DEBUG', true);
        $file = tempnam(sys_get_temp_dir(), 'pa-log');
        ini_set('error_log', $file);

        Log::error('shown message');

        $this->assertStringContainsString('shown message', (string) file_get_contents($file));
        unlink($file);
    }
}
