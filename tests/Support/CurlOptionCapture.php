<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests\Support;

final class CurlOptionCapture
{
    private static array $options = [];

    public static function reset(): void
    {
        self::$options = [];
    }

    /** @param mixed $value */
    public static function record(int $option, $value): void
    {
        self::$options[$option] = $value;
    }

    /** @return mixed */
    public static function get(int $option)
    {
        return self::$options[$option] ?? null;
    }

    /** curl/libcurl default: peer verification on when CURLOPT_SSL_VERIFYPEER was not set. */
    public static function peerVerificationEnabled(): bool
    {
        $value = self::get(CURLOPT_SSL_VERIFYPEER);

        if ($value === null) {
            return true;
        }

        return $value === true || $value === 1;
    }

    /** Matches Http.php when sslverify defaults to true; absent option means verify-host 2. */
    public static function hostVerificationEnabled(): bool
    {
        $value = self::get(CURLOPT_SSL_VERIFYHOST);

        if ($value === null) {
            return true;
        }

        return $value === 2;
    }
}

namespace PanelAlpha\Migrator\Helpers;

/** @param mixed $value */
function curl_setopt($handle, int $option, $value): bool
{
    \PanelAlpha\Migrator\Tests\Support\CurlOptionCapture::record($option, $value);

    return \curl_setopt($handle, $option, $value);
}
