<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class HttpResponse
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Represents an HTTP response.
 */
class HttpResponse
{
    /** @var string|false */
    private $body;
    private int $statusCode;

    /**
     * HttpResponse constructor.
     *
     * @param string|false $body
     * @param int          $statusCode
     */
    public function __construct($body, int $statusCode)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
    }

    /**
     * Gets the response body.
     *
     * @return string|false
     */
    public function getBody()
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
