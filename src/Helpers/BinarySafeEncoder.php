<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class BinarySafeEncoder
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Handles encoding/decoding of non-UTF8 values in database row data
 * to allow safe JSON serialization during migration.
 */
class BinarySafeEncoder
{
    /**
     * Scans row data and base64-encodes any values that are not valid UTF-8.
     * Returns the sanitized data and a map of encoded cell positions.
     *
     * @param array $rows Array of rows (each row is an array of values)
     * @return array{data: array, binary_map: array} Encoded data and position map
     */
    public static function encode(array $rows): array
    {
        $binaryMap = [];

        foreach ($rows as $rowIndex => &$row) {
            foreach ($row as $colIndex => &$value) {
                if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                    $value = base64_encode($value);
                    $binaryMap[] = [$rowIndex, $colIndex];
                }
            }
        }

        return [
            'data' => $rows,
            'binary_map' => $binaryMap,
        ];
    }

    /**
     * Decodes base64-encoded values in row data using the binary map.
     *
     * @param array $rows Array of rows
     * @param array $binaryMap Array of [rowIndex, colIndex] pairs
     * @return array Decoded rows
     */
    public static function decode(array $rows, array $binaryMap): array
    {
        foreach ($binaryMap as $position) {
            [$rowIndex, $colIndex] = $position;

            if (isset($rows[$rowIndex][$colIndex])) {
                $decoded = base64_decode($rows[$rowIndex][$colIndex], true);

                if ($decoded !== false) {
                    $rows[$rowIndex][$colIndex] = $decoded;
                }
            }
        }

        return $rows;
    }
}
