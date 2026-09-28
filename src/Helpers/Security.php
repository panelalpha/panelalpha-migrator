<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Class Security
 *
 * @package WordpressMigrator\Helpers
 *
 * Provides security-related functions like nonces and capability checks.
 */
class Security
{
    private static array $nonces = [];

    /**
     * Creates a nonce.
     *
     * @param string $action
     * @return string
     * @throws \Exception
     */
    public static function createNonce(string $action = 'default'): string
    {
        $token = bin2hex(random_bytes(16));
        self::$nonces[$action] = [
            'token' => $token,
            'time' => time()
        ];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['nonces'][$action] = $token;

        return $token;
    }

    /**
     * Verifies a nonce.
     *
     * @param string $nonce
     * @param string $action
     * @return bool
     */
    public static function verifyNonce(string $nonce, string $action = 'default'): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return isset($_SESSION['nonces'][$action]) && $_SESSION['nonces'][$action] === $nonce;
    }

    /**
     * Escapes HTML characters in a string.
     *
     * @param string $text
     * @return string
     */
    public static function escHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Checks if the current user has a specific capability.
     *
     * @param string $capability
     * @return bool
     */
    public static function currentUserCan(string $capability): bool
    {
        // Simplified permission check - can be extended
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // For now, check if user is logged in and has admin role
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'administrator';
    }
}

