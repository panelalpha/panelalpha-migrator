<?php

namespace WordpressMigrator\Api\Controllers;

use Exception;
use WordpressMigrator\Api\Middleware\ResponseFormatter;
use WordpressMigrator\Helpers\Filesystem;
use WordpressMigrator\Helpers\TokenHelper;

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
    public function __construct(?ResponseFormatter $responseFormatter = null)
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

        return ['token' => TokenHelper::readToken($this->tokenFilePath)];
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
        if ($content === false) {
            throw new Exception('Failed to write config.php file. Check file permissions.');
        }

        $updated = preg_replace(
            "/'token'\s*=>\s*'[^']*'/",
            "'token' => '" . $token . "'",
            $content,
            1,
            $count
        );

        if (!is_string($updated) || $count !== 1) {
            throw new Exception('Failed to save token to config.php.');
        }

        $permissions = @fileperms($this->tokenFilePath);

        if (!Filesystem::writeFileAtomic($this->tokenFilePath, $updated)) {
            throw new Exception('Failed to write config.php file. Check file permissions.');
        }

        if ($permissions !== false) {
            @chmod($this->tokenFilePath, $permissions & 0777);
        }

        // The opcode cache may keep serving the previous file until it is invalidated.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->tokenFilePath, true);
        }

        // Verify the raw file content, not an include that the opcode cache can answer.
        $stored = file_get_contents($this->tokenFilePath);
        if (!is_string($stored) || !preg_match("/'token'\s*=>\s*'([^']*)'/", $stored, $matches) || $matches[1] !== $token) {
            throw new Exception('Failed to save token to config.php.');
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

        $configuredToken = TokenHelper::readToken($configFilePath);
        if ($configuredToken === '') {
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
