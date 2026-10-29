<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
final class WordPressStubsTest extends TestCase
{
    public function testRequestReadsHeaderRegardlessOfCaseOrSeparator(): void
    {
        require __DIR__ . '/Support/WordPressStubs.php';

        $request = new \WP_REST_Request([], ['X-Migrator-Token' => 'secret']);

        self::assertSame('secret', $request->get_header('X-Migrator-Token'));
        self::assertSame('secret', $request->get_header('x_migrator_token'));
        self::assertSame('secret', $request->get_header('x-MIGRATOR_token'));
        self::assertNull($request->get_header('other-header'));
    }
}
