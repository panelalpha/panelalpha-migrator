<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Request
 *
 * @package WordpressMigrator\Helpers
 *
 * Represents an HTTP request.
 */
class Request
{
    private array $params = [];
    private array $jsonParams = [];

    /**
     * Request constructor.
     */
    public function __construct()
    {
        $this->params = array_merge($_GET, $_POST);

        $json = file_get_contents('php://input');
        if ($json) {
            $this->jsonParams = json_decode($json, true) ?? [];
        }
    }

    /**
     * Gets a parameter from the request.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $this->jsonParams[$key] ?? $default;
    }

    /**
     * Gets JSON parameters from the request.
     *
     * @return array
     */
    public function getJsonParams(): array
    {
        return $this->jsonParams;
    }
}
