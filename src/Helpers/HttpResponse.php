<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class HttpResponse
 *
 * @package WordpressMigrator\Helpers
 *
 * Represents an HTTP response.
 */
class HttpResponse
{
    private string|false $body;
    private int $statusCode;

    /**
     * HttpResponse constructor.
     *
     * @param string|false $body
     * @param int          $statusCode
     */
    public function __construct(string|false $body, int $statusCode)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
    }

    /**
     * Gets the response body.
     *
     * @return string|false
     */
    public function getBody(): string|false
    {
        return $this->body;
    }

    /**
     * Gets the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
