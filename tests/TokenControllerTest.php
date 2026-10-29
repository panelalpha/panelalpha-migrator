<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Api\Controllers\TokenController;
use PanelAlpha\Migrator\Helpers\TokenHelper;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TokenControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
        require_once __DIR__ . '/Support/OptionStubs.php';
    }

    public function testMissingTokenReturnsNoTokenAndRejectsValidation(): void
    {
        $controller = new TokenController();

        $this->assertSame('no_token', $controller->getCurrentToken(null)->get_error_code());
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'anything'])));
    }

    public function testGeneratePersistsTheTokenInAnOptionAndValidationUsesIt(): void
    {
        update_option(TokenHelper::TOKEN_OPTION, 'old-token', false);
        $controller = new TokenController();

        $token = $controller->generateAndSaveToken(null)->get_data()['token'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, get_option(TokenHelper::TOKEN_OPTION));
        $this->assertFalse($GLOBALS['wpm_option_autoload'][TokenHelper::TOKEN_OPTION]);
        $this->assertSame($token, $controller->getCurrentToken(null)->get_data()['token']);
        $this->assertTrue(TokenController::validateToken(new \WP_REST_Request(['token' => $token])));
        $this->assertTrue(TokenController::validateToken(new \WP_REST_Request([], ['x_migrator_token' => $token])));
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'old-token'])));
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'wrong'], ['x_migrator_token' => $token])));
    }

    public function testEmptyTokenIsRejected(): void
    {
        update_option(TokenHelper::TOKEN_OPTION, '', false);

        $this->assertSame('no_token', (new TokenController())->getCurrentToken(null)->get_error_code());
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => 'anything'])));
    }

    public function testGenerateReportsFailureWhenTheTokenCannotBeStored(): void
    {
        $GLOBALS['wpm_options_read_only'] = true;
        $controller = new TokenController();

        $response = $controller->generateAndSaveToken(null);

        $this->assertSame('generation_failed', $response->get_error_code());
        $this->assertSame('no_token', $controller->getCurrentToken(null)->get_error_code());
    }

    public function testArrayTokensAreRejectedWithoutAnError(): void
    {
        update_option(TokenHelper::TOKEN_OPTION, 'real-token', false);

        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request(['token' => ['x']])));
        $this->assertFalse(TokenController::validateToken(new \WP_REST_Request([], ['x_migrator_token' => ['x']])));
    }
}
