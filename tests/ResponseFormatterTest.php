<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Api\Middleware\ResponseFormatter;

/**
 * Each test runs in its own process because it defines the global rest_ensure_response()
 * function and WP_Error/WP_REST_Response classes, which must not leak into other tests.
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
final class ResponseFormatterTest extends TestCase
{
    public function testSuccessWrapsDataInARestResponseWhenWordPressIsPresent(): void
    {
        require __DIR__ . '/Support/WordPressStubs.php';

        $result = ResponseFormatter::success(['success' => true, 'token' => 'abc']);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(['success' => true, 'token' => 'abc'], $result->get_data());
    }

    public function testErrorReturnsAWpErrorWhenWordPressIsPresent(): void
    {
        require __DIR__ . '/Support/WordPressStubs.php';

        $result = ResponseFormatter::error('no_token', 'No token configured.', ['status' => 404]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('no_token', $result->get_error_code());
        $this->assertSame('No token configured.', $result->get_error_message());
        $this->assertSame(['status' => 404], $result->get_error_data());
    }

    public function testConvertKeysToSnakeCaseConvertsCamelCaseKeysRecursively(): void
    {
        $result = ResponseFormatter::convertKeysToSnakeCase([
            'migrationId' => 'mig_1',
            'nested' => ['fileCount' => 3, 'alreadySnake_case' => 'x'],
        ]);

        $this->assertSame([
            'migration_id' => 'mig_1',
            'nested' => ['file_count' => 3, 'already_snake_case' => 'x'],
        ], $result);
    }

    public function testConvertKeysToSnakeCaseLeavesSnakeCaseKeysUnchanged(): void
    {
        $result = ResponseFormatter::convertKeysToSnakeCase(['migration_id' => 'mig_1', 'total_files' => 2]);

        $this->assertSame(['migration_id' => 'mig_1', 'total_files' => 2], $result);
    }
}
