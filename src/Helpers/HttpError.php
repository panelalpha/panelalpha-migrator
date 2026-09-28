<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class HttpError
 *
 * @package WordpressMigrator\Helpers
 *
 * Represents an HTTP error.
 */
class HttpError
{
    private string $message;

    /**
     * HttpError constructor.
     *
     * @param string $message
     */
    public function __construct(string $message)
    {
        $this->message = $message;
    }

    /**
     * Gets the error message.
     *
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->message;
    }
}
