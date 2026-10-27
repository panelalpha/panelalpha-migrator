<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Api\Controllers\TokenController;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TokenControllerTest extends TestCase
{
    use TemporaryDirectory;

    private string $configFile;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
        $directory = $this->createTemporaryDirectory();
        define('WP_MIGRATOR_PLUGIN_DIR', $directory);
        $this->configFile = $directory . 'config.php';
    }

    public function testMissingConfigReturnsNoTokenAndRejectsValidation(): void
    {
        $controller = new TokenController();

        $this->assertSame('no_token', $controller->getCurrentToken(null)->get_error_code());
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'anything'])));
        $this->assertSame('generation_failed', $controller->generateAndSaveToken(null)->get_error_code());
    }

    public function testGeneratePersistsTokenInTemporaryConfigAndValidationUsesIt(): void
    {
        file_put_contents($this->configFile, "<?php\nreturn ['token' => 'old-token', 'other' => 'kept'];\n");
        $controller = new TokenController();

        $response = $controller->generateAndSaveToken(null)->get_data();
        $token = $response['token'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $controller->getCurrentToken(null)->get_data()['token']);
        $this->assertSame('kept', (include $this->configFile)['other']);
        $this->assertTrue(TokenController::validateToken(new \WP_REST_Request(['token' => $token])));
        $this->assertTrue(TokenController::validateToken(new \WP_REST_Request([], ['x_migrator_token' => $token])));
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'wrong'], ['x_migrator_token' => $token])));
    }

    public function testEmptyTokenIsRejected(): void
    {
        file_put_contents($this->configFile, "<?php\nreturn ['token' => ''];\n");

        $this->assertSame('no_token', (new TokenController())->getCurrentToken(null)->get_error_code());
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'anything'])));
    }

    public function testGenerateReportsFailureWhenTheTokenEntryCannotBeReplaced(): void
    {
        $original = "<?php\nreturn ['token' => \"previous-token\", 'other' => 'kept'];\n";
        file_put_contents($this->configFile, $original);
        $controller = new TokenController();

        $response = $controller->generateAndSaveToken(null);

        $this->assertSame('generation_failed', $response->get_error_code());
        $this->assertSame($original, file_get_contents($this->configFile));
        $this->assertSame('previous-token', $controller->getCurrentToken(null)->get_data()['token']);
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'not-the-previous-token'])));
        $this->assertTrue(TokenController::validateToken(new \WP_REST_Request(['token' => 'previous-token'])));
    }

    public function testArrayTokensAreRejectedWithoutAnError(): void
    {
        file_put_contents($this->configFile, "<?php\nreturn ['token' => 'real-token'];\n");

        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => ['x']])));
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request([], ['x_migrator_token' => ['x']])));
    }

    public function testGenerateLeavesNoTemporaryFileAndKeepsThePermissions(): void
    {
        file_put_contents($this->configFile, "<?php\nreturn ['token' => 'old-token'];\n");
        chmod($this->configFile, 0640);

        (new TokenController())->generateAndSaveToken(null);

        clearstatcache();
        $this->assertSame(0640, fileperms($this->configFile) & 0777);
        $this->assertSame([$this->configFile], glob(dirname($this->configFile) . '/config.php*'));
    }

    public function testGenerateWritesConfigInPlaceWhenThePluginFolderIsReadOnly(): void
    {
        file_put_contents($this->configFile, "<?php\nreturn ['token' => 'old-token'];\n");
        chmod($this->configFile, 0666);
        chmod(dirname($this->configFile), 0555);

        try {
            $token = (new TokenController())->generateAndSaveToken(null)->get_data()['token'];

            $this->assertTrue(TokenController::validateToken(new \WP_REST_Request(['token' => $token])));
            $this->assertSame([$this->configFile], glob(dirname($this->configFile) . '/config.php*'));
        } finally {
            chmod(dirname($this->configFile), 0755);
        }
    }

    public function testNewTokenWorksAtOnceWhenTheOpcodeCacheKeepsTheOldConfig(): void
    {
        file_put_contents($this->configFile, "<?php\nreturn ['token' => 'old-token'];\n");
        $script = dirname($this->configFile) . '/check.php';
        file_put_contents($script, '<?php
            require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
            require ' . var_export(__DIR__ . '/Support/WordPressStubs.php', true) . ';
            define("WP_MIGRATOR_PLUGIN_DIR", ' . var_export(dirname($this->configFile) . '/', true) . ');
            include WP_MIGRATOR_PLUGIN_DIR . "config.php";
            $controller = new WordpressMigrator\\Api\\Controllers\\TokenController();
            $token = $controller->generateAndSaveToken(null)->get_data()["token"];
            echo (include WP_MIGRATOR_PLUGIN_DIR . "config.php")["token"], "|";
            echo $controller->getCurrentToken(null)->get_data()["token"] === $token ? "current" : "stale", "|";
            echo WordpressMigrator\\Api\\Controllers\\TokenController::validateToken(new WP_REST_Request(["token" => $token])) ? "new-ok" : "new-refused", "|";
            echo WordpressMigrator\\Api\\Controllers\\TokenController::validateToken(new WP_REST_Request(["token" => "old-token"])) ? "old-ok" : "old-refused";
        ');

        // JIT is off because it warns on stderr when PCOV or Xdebug is loaded, which fails an isolated test.
        $output = shell_exec(implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.restrict_api=/nonexistent', '-d', 'opcache.file_update_protection=0',
            '-d', 'opcache.jit=disable', $script,
        ])) . ' 2>/dev/null');

        if (!is_string($output) || strpos($output, 'old-token|') !== 0) {
            $this->markTestSkipped('The opcode cache is not available in a CLI subprocess: ' . $output);
        }

        $this->assertSame('old-token|current|new-ok|old-refused', $output);
    }
}
