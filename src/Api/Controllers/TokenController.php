<?php

namespace WordpressMigrator\Api\Controllers;

use Exception;
use WordpressMigrator\Api\Middleware\ResponseFormatter;

/**
 * Class TokenController
 *
 * @package WordpressMigrator\Api\Controllers
 *
 * Handles token generation and management.
 */
class TokenController
{
    private $responseFormatter;
    private $pluginDir;
    private $tokenFilePath;

    /**
     * TokenController constructor.
     *
     * @param ResponseFormatter|null $responseFormatter
     */
    public function __construct(ResponseFormatter $responseFormatter = null)
    {
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
        $this->pluginDir = defined('WP_MIGRATOR_PLUGIN_DIR') ? WP_MIGRATOR_PLUGIN_DIR : dirname(__DIR__, 3) . '/';
        $this->tokenFilePath = $this->pluginDir . 'config.php';
    }

    /**
     * Gets the current token from config.php file.
     *
     * @param mixed $request
     * @return mixed
     */
    public function getCurrentToken($request)
    {
        try {
            $tokenData = $this->readTokenFromFile();

            if (empty($tokenData['token'])) {
                return $this->responseFormatter->error('no_token', 'No token configured. Please generate a new token.', ['status' => 404]);
            }

            return $this->responseFormatter->success([
                'success' => true,
                'token' => $tokenData['token']
            ]);

        } catch (Exception $e) {
            return $this->responseFormatter->error('fetch_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Generates and saves a new token to config.php file.
     *
     * @param mixed $request
     * @return mixed
     */
    public function generateAndSaveToken($request)
    {
        try {
            $token = bin2hex(random_bytes(32));

            $this->saveTokenToFile($token);

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
     * Reads token from config.php file.
     *
     * @return array
     */
    private function readTokenFromFile(): array
    {
        if (!file_exists($this->tokenFilePath)) {
            return ['token' => ''];
        }

        $tokenData = include $this->tokenFilePath;

        if (!is_array($tokenData)) {
            return ['token' => ''];
        }

        return $tokenData;
    }

    /**
     * Saves token to config.php file.
     *
     * @param string $token
     * @return void
     * @throws Exception
     */
    private function saveTokenToFile(string $token): void
    {
        if (!file_exists($this->tokenFilePath)) {
            throw new Exception('config.php file does not exist. Please create it first.');
        }

        $content = file_get_contents($this->tokenFilePath);

        $content = preg_replace(
            "/'token'\s*=>\s*'[^']*'/",
            "'token' => '" . $token . "'",
            $content
        );

        if (file_put_contents($this->tokenFilePath, $content) === false) {
            throw new Exception('Failed to write config.php file. Check file permissions.');
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
        $pluginDir = defined('WP_MIGRATOR_PLUGIN_DIR') ? WP_MIGRATOR_PLUGIN_DIR : dirname(__DIR__, 3) . '/';
        $configFilePath = $pluginDir . 'config.php';

        if (!file_exists($configFilePath)) {
            return false;
        }

        $configData = include $configFilePath;
        if (!is_array($configData) || empty($configData['token'])) {
            return false;
        }

        $configuredToken = $configData['token'];

        if (is_object($request) && method_exists($request, 'get_param')) {
            $queryToken = $request->get_param('token');
            if ($queryToken) {
                return hash_equals($configuredToken, $queryToken);
            }

            if (method_exists($request, 'get_header')) {
                $headerToken = $request->get_header('x_migrator_token');
                if ($headerToken) {
                    return hash_equals($configuredToken, $headerToken);
                }
            }
        } elseif (isset($_GET['token'])) {
            return hash_equals($configuredToken, $_GET['token']);
        }

        return false;
    }
}
