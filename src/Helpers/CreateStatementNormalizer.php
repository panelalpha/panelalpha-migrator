<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

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

    /**
     * Points a CREATE TABLE statement at temporary tables.
     *
     * Only the table name in the CREATE TABLE header and the REFERENCES targets are changed, so
     * columns and comments that happen to contain a table name stay as they are. Constraint
     * names are dropped because they must be unique in the whole database: a copy of a table
     * would collide with the live table that still holds the same name.
     *
     * @param string                $createStatement
     * @param array<string, string> $tempNames       Source table name => temporary table name
     * @return string
     * @throws \InvalidArgumentException When the table of the statement is not in the map
     */
    public static function forTempTable(string $createStatement, array $tempNames): string
    {
        $header = '/^(\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`((?:[^`]|``)+)`/i';

        if (!preg_match($header, $createStatement, $matches)) {
            throw new \InvalidArgumentException('Not a CREATE TABLE statement');
        }

        $source = str_replace('``', '`', $matches[2]);
        if (!isset($tempNames[$source])) {
            throw new \InvalidArgumentException(esc_html("Table {$source} has no temporary name"));
        }

        $statement = preg_replace_callback(
            $header,
            static function (array $found) use ($tempNames, $source): string {
                return $found[1] . self::quoteName($tempNames[$source]);
            },
            $createStatement,
            1
        ) ?? $createStatement;

        $statement = preg_replace_callback(
            '/\bREFERENCES\s+`((?:[^`]|``)+)`/i',
            static function (array $found) use ($tempNames): string {
                $name = str_replace('``', '`', $found[1]);

                return isset($tempNames[$name]) ? 'REFERENCES ' . self::quoteName($tempNames[$name]) : $found[0];
            },
            $statement
        ) ?? $statement;

        return preg_replace('/\bCONSTRAINT\s+`(?:[^`]|``)+`\s+(?=FOREIGN\s+KEY\b|CHECK\b)/i', '', $statement) ?? $statement;
    }

    private static function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
