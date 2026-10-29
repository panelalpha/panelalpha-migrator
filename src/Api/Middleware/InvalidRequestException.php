<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Api\Middleware;

use InvalidArgumentException;

/**
 * A required request parameter is missing or invalid. Answered with 400.
 */
class InvalidRequestException extends InvalidArgumentException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
