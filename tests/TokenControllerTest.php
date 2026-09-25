<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Api\Controllers\TokenController;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

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

    public function testExistingConfigWithoutTokenEntryReportsSuccessButDoesNotPersistToken(): void
    {
        $original = "<?php\nreturn ['other' => 'kept'];\n";
        file_put_contents($this->configFile, $original);
        $controller = new TokenController();

        $response = $controller->generateAndSaveToken(null);

        $this->assertTrue($response->get_data()['success']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->get_data()['token']);
        $this->assertSame($original, file_get_contents($this->configFile));
        $this->assertSame('no_token', $controller->getCurrentToken(null)->get_error_code());
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => $response->get_data()['token']])));
    }
}
