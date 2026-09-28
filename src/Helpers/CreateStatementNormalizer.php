<?php

declare(strict_types=1);

namespace WordpressMigrator\Helpers;

/**
 * Normalizes CREATE TABLE statements for destination servers with strict MariaDB settings.
 */
class CreateStatementNormalizer
{
    public static function normalize(string $createStatement): string
    {
        $statement = trim($createStatement);

        $statement = preg_replace('/\/\*!\d+\s+TABLESPACE[^*]*\*+\//i', '', $statement) ?? $statement;
        $statement = preg_replace('/\s+TABLESPACE\s+`?[\w-]+`?/i', '', $statement) ?? $statement;
        $statement = preg_replace('/\s+TYPE\s*=\s*\w+/i', '', $statement) ?? $statement;
        $statement = preg_replace('/\s+PACK_KEYS\s*=\s*\d+/i', '', $statement) ?? $statement;
        $statement = preg_replace('/\s+ROW_FORMAT\s*=\s*(?:FIXED|COMPRESSED|REDUNDANT|COMPACT)/i', '', $statement) ?? $statement;

        if (preg_match('/\bENGINE\s*=/i', $statement)) {
            $statement = preg_replace('/\bENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $statement) ?? $statement;
        } else {
            $statement = preg_replace('/;\s*$/', ' ENGINE=InnoDB;', $statement) ?? $statement;
        }

        return preg_replace('/\s{2,}/', ' ', $statement) ?? $statement;
    }
}
