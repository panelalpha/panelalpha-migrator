<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Services\Migration;

use Exception;

/**
 * The destination cannot take the migration. The code tells the caller why:
 * destination_incompatible for a missing or blocked plugin, destination_not_ready
 * for an unreachable destination or a token it does not accept.
 */
class DestinationHandshakeException extends Exception
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct(esc_html($message));
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
