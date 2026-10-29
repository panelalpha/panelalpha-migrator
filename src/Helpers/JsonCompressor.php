<?php

namespace PanelAlpha\Migrator\Helpers;

/**
 * Class JsonCompressor
 *
 * @package PanelAlpha\Migrator\Helpers
 *
 * Compresses JSON data by using shorter keys to reduce file size.
 */
class JsonCompressor
{
    /**
     * Mapping of long keys to short keys.
     */
    private const KEY_MAP = [
        // Common keys
        'migration_id' => 'm',
        'chunk_id' => 'c',
        'type' => 't',
        'timestamp' => 'ts',
        'checksum' => 'cs',

        // Chunk related
        'chunk_number' => 'n',
        'total_chunks' => 'tc',
        'chunk_info' => 'ci',
        'offset' => 'o',
        'limit' => 'l',
        'size' => 's',
        'actual_rows' => 'ar',

        // Table related
        'table' => 'tb',
        'table_name' => 'tn',
        'name' => 'nm',
        'columns' => 'col',
        'data' => 'd',
        'indexes' => 'idx',
        'create_statement' => 'cst',
        'rowCount' => 'rc',
        'structureSent' => 'ss',

        // File related
        'file' => 'f',
        'path' => 'p',
        'permissions' => 'pm',
        'modified' => 'md',
        'content' => 'ct',

        // Migration info
        'migrationId' => 'mid',
        'siteUrl' => 'su',        // Add camelCase version
        'site_url' => 'su',       // Keep snake_case for compatibility
        'homeUrl' => 'hu',        // Add homeUrl mapping
        'home_url' => 'hu',       // Keep snake_case for compatibility
        'destinationUrl' => 'du',
        'destination_url' => 'du',
        'tables' => 'tbs',
        'files' => 'fs',
        'status' => 'st',
        'chunks' => 'ch',
        'totalChunks' => 'tch',

        // Status related
        'received_chunks' => 'rch',
        'restored_chunks' => 'rsh',
        'sent_chunks' => 'sch',
        'total_files' => 'tf',
        'total_tables' => 'tt',
        'files_restored' => 'fr',
        'tables_restored' => 'tr',
        'files_total' => 'ftl',
        'tables_total' => 'ttl',
        'source_url' => 'sru',
        'source_token' => 'stk',      // Add source_token mapping
        'sourceToken' => 'stk',       // Add sourceToken camelCase mapping
        'source_table_prefix' => 'stp',
        'estimated_chunks' => 'ec',   // Add estimated_chunks mapping
        'estimatedChunks' => 'ec',    // Add estimatedChunks camelCase mapping
        'summary' => 'sm',            // Add summary mapping
        'binary_map' => 'bm',
    ];

    /**
     * Reverse mapping for decompression.
     *
     * @var array|null
     */
    private static $reverseMap = null;

    /**
     * Compresses an array by replacing long keys with short keys.
     *
     * @param array $data
     * @return array
     */
    public static function compress(array $data): array
    {
        return self::transformKeys($data, self::KEY_MAP);
    }

    /**
     * Decompresses an array by replacing short keys with long keys.
     *
     * @param array $data
     * @return array
     */
    public static function decompress(array $data): array
    {
        if (self::$reverseMap === null) {
            self::$reverseMap = array_flip(self::KEY_MAP);
        }

        return self::transformKeys($data, self::$reverseMap);
    }

    /**
     * Checks if data appears to be compressed.
     *
     * @param array $data
     * @return bool
     */
    public static function isCompressed(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Check if any short keys exist in the data
        $shortKeys = array_values(self::KEY_MAP);
        $dataKeys = array_keys($data);

        foreach ($shortKeys as $shortKey) {
            if (in_array($shortKey, $dataKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Transforms keys in an array recursively.
     *
     * @param mixed $data
     * @param array $keyMap
     * @return mixed
     */
    private static function transformKeys($data, array $keyMap)
    {
        if (!is_array($data)) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            $newKey = $keyMap[$key] ?? $key;

            if (is_array($value)) {
                $result[$newKey] = self::transformKeys($value, $keyMap);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Compresses and encodes data to JSON.
     *
     * @param array $data
     * @param int   $flags
     * @return string
     */
    public static function compressToJson(array $data, int $flags = 0): string
    {
        $compressed = self::compress($data);
        return json_encode($compressed, $flags);
    }

    /**
     * Decodes JSON and decompresses data.
     *
     * @param string $json
     * @return array
     */
    public static function decompressFromJson(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        // Auto-detect if compression is needed
        if (self::isCompressed($data)) {
            return self::decompress($data);
        }

        return $data;
    }
}
