<?php

namespace PanelAlpha\Migrator\Api\Controllers;

use Exception;
use PanelAlpha\Migrator\Api\Middleware\ResponseFormatter;
use PanelAlpha\Migrator\Helpers\TokenHelper;

/**
 * Class TokenController
 *
 * @package PanelAlpha\Migrator\Api\Controllers
 *
 * Handles token generation and management.
 */
class TokenController
{
    private $responseFormatter;

    /**
     * TokenController constructor.
     *
     * @param ResponseFormatter|null $responseFormatter
     */
    public function __construct(?ResponseFormatter $responseFormatter = null)
    {
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
    }

    /**
     * Gets the current token.
     *
     * @param mixed $request
     * @return mixed
     */
    public function getCurrentToken($request)
    {
        try {
            $token = TokenHelper::getToken();

            if ($token === null) {
                return $this->responseFormatter->error('no_token', 'No token configured. Please generate a new token.', ['status' => 404]);
            }

            return $this->responseFormatter->success([
                'success' => true,
                'token' => $token
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('fetch_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Generates and saves a new token.
     *
     * @param mixed $request
     * @return mixed
     */
    public function generateAndSaveToken($request)
    {
        try {
            $token = bin2hex(random_bytes(32));

            if (!TokenHelper::saveToken($token)) {
                throw new Exception('Failed to save the token.');
            }

            return $this->responseFormatter->success([
                'success' => true,
                'token' => $token,
                'message' => 'Token generated and saved successfully'
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('generation_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Validates incoming request token.
     *
     * @param mixed $request
     * @return bool
     */
    public static function validateToken($request): bool
    {
        $configuredToken = TokenHelper::getToken();
        if ($configuredToken === null) {
            return false;
        }

        if (is_object($request) && method_exists($request, 'get_param')) {
            $queryToken = $request->get_param('token');
            if ($queryToken) {
                return is_string($queryToken) && hash_equals($configuredToken, $queryToken);
            }

            if (method_exists($request, 'get_header')) {
                $headerToken = $request->get_header('x_migrator_token');
                if ($headerToken) {
                    return is_string($headerToken) && hash_equals($configuredToken, $headerToken);
                }
            }
        } elseif (isset($_GET['token'])) {
            return is_string($_GET['token']) && hash_equals($configuredToken, $_GET['token']);
        }

        return false;
    }
}
