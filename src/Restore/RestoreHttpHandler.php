<?php

namespace WordpressMigrator\Restore;

use WordpressMigrator\Helpers\TokenHelper;

/**
 * Class RestoreHttpHandler
 *
 * @package WordpressMigrator\Restore
 *
 * Handles HTTP requests for the restore process.
 */
class RestoreHttpHandler
{
    private string $authToken;

    /**
     * RestoreHttpHandler constructor.
     *
     * @param string|null $authToken
     */
    public function __construct(?string $authToken = null)
    {
        $token = $authToken ?? TokenHelper::getToken();
        $this->authToken = is_string($token) ? $token : '';
    }

    /**
     * Authenticates the request.
     * Requires token to be configured in config.php file.
     */
    public function authenticate(): void
    {
        // Check if auth token is configured
        if (empty($this->authToken)) {
            $this->sendJson([
                'error' => 'Authentication required - token not configured in config.php file'
            ], 500);
        }

        $token = $_SERVER['HTTP_X_MIGRATOR_TOKEN'] ?? $_GET['token'] ?? '';

        if (!is_string($token) || $token === '') {
            $this->sendJson(['error' => 'Unauthorized - missing authentication token'], 401);
        }

        if (!hash_equals($this->authToken, $token)) {
            $this->sendJson(['error' => 'Unauthorized - invalid token'], 403);
        }
    }

    /**
     * Sends a JSON response.
     *
     * @param array $data
     * @param int   $status
     */
    public function sendJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($data);
        exit;
    }

    /**
     * Gets JSON input from the request body.
     *
     * @return array
     */
    public function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendJson(['error' => 'Invalid JSON input'], 400);
        }

        return $data;
    }

    /**
     * Gets JSON input from the request body.
     *
     * @return array
     */
    public function getJsonInputBase64(): array
    {
        $input = file_get_contents('php://input');

        $encoding = $_SERVER['HTTP_X_MIGRATOR_ENCODING'] ?? '';
        $isBase64 = $encoding === 'base64';

        if (!$isBase64 && !empty($input)) {
            $trimmed = ltrim($input);
            if (!empty($trimmed) && $trimmed[0] !== '{' && $trimmed[0] !== '[') {
                $isBase64 = true;
            }
        }

        if ($isBase64) {
            $decoded = base64_decode($input, true);
            if ($decoded !== false) {
                $input = $decoded;
            }
        }

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendJson(['error' => 'Invalid JSON input'], 400);
        }

        return $data;
    }

    /**
     * Gets the action from the request.
     *
     * @return string
     */
    public function getAction(): string
    {
        return $_GET['action'] ?? $_POST['action'] ?? '';
    }

    /**
     * Gets the migration ID from the request.
     *
     * @return string
     */
    public function getMigrationId(): string
    {
        return $_GET['migration_id'] ?? '';
    }
}
