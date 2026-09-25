<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;

final class RestoreHttpHandlerRejectionTest extends TestCase
{
    public function testAuthenticationRejectsUnconfiguredToken(): void
    {
        $output = $this->runAuthentication('', 'secret');

        self::assertSame(
            ['error' => 'Authentication required - token not configured in config.php file'],
            json_decode($output, true),
            $output
        );
        self::assertStringNotContainsString('accepted', $output);
    }

    private function runAuthentication(?string $configuredToken, ?string $suppliedToken): string
    {
        $source = dirname(__DIR__) . '/src/';
        $code = 'require ' . var_export($source . 'Helpers/TokenHelper.php', true) . ';'
            . 'require ' . var_export($source . 'Restore/RestoreHttpHandler.php', true) . ';'
            . '$_SERVER[' . var_export('HTTP_X_MIGRATOR_TOKEN', true) . '] = ' . var_export($suppliedToken, true) . ';'
            . '(new \WordpressMigrator\Restore\RestoreHttpHandler('
            . var_export($configuredToken, true) . '))->authenticate();'
            . 'echo ' . var_export('accepted', true) . ';';

        $process = proc_open([PHP_BINARY, '-r', $code], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        return $output;
    }
}
