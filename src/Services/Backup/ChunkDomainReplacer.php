<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Backup;

/**
 * Class ChunkDomainReplacer
 *
 * @package WordpressMigrator\Services\Backup
 *
 * Handles domain replacement in chunk data before sending to destination.
 */
class ChunkDomainReplacer
{
    private string $sourceUrl;
    private string $destinationUrl;
    private array $replacements;

    /**
     * ChunkDomainReplacer constructor.
     *
     * @param string $sourceUrl Source domain URL
     * @param string $destinationUrl Destination domain URL
     */
    public function __construct(string $sourceUrl, string $destinationUrl)
    {
        $this->sourceUrl = rtrim($sourceUrl, '/');
        $this->destinationUrl = rtrim($destinationUrl, '/');
        $this->prepareReplacements();
    }

    /**
     * Prepares all URL variations for replacement.
     */
    private function prepareReplacements(): void
    {
        $sourceHttp = str_replace('https://', 'http://', $this->sourceUrl);
        $sourceHttps = str_replace('http://', 'https://', $this->sourceUrl);
        $destHttp = str_replace('https://', 'http://', $this->destinationUrl);
        $destHttps = str_replace('http://', 'https://', $this->destinationUrl);

        $this->replacements = [
            $sourceHttps => $destHttps,
            $sourceHttp => $destHttp,
            urlencode($sourceHttps) => urlencode($destHttps),
            urlencode($sourceHttp) => urlencode($destHttp),
            addslashes($sourceHttps) => addslashes($destHttps),
            addslashes($sourceHttp) => addslashes($destHttp),
        ];
    }

    /**
     * Replaces domains in chunk data array.
     *
     * @param array $data Chunk data
     * @return array Data with replaced domains
     */
    public function replaceInData(array $data): array
    {
        if ($this->shouldSkipReplacement()) {
            return $data;
        }

        $processedData = [];
        foreach ($data as $row) {
            $processedRow = [];
            foreach ($row as $key => $value) {
                $processedRow[$key] = $this->replaceInValue($value);
            }
            $processedData[] = $processedRow;
        }

        return $processedData;
    }

    /**
     * Checks if replacement should be skipped.
     *
     * @return bool
     */
    private function shouldSkipReplacement(): bool
    {
        return empty($this->sourceUrl) 
            || empty($this->destinationUrl) 
            || $this->sourceUrl === $this->destinationUrl;
    }

    /**
     * Replaces domains in a value, handling serialized data.
     *
     * @param mixed $value Value to process
     * @return mixed Processed value
     */
    private function replaceInValue($value)
    {
        if (is_null($value)) {
            return $value;
        }

        if (is_string($value) && $this->isSerialized($value)) {
            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            if ($unserialized !== false && !$this->containsIncompleteClass($unserialized)) {
                $replaced = $this->replaceInNestedData($unserialized);
                return serialize($replaced);
            }

            // Serialized data holding objects whose class is not loaded here
            // (e.g. ActionScheduler_SimpleSchedule) cannot be safely rebuilt,
            // so leave the original value untouched.
            return $value;
        }

        if (is_string($value)) {
            foreach ($this->replacements as $search => $replace) {
                $value = str_replace($search, $replace, $value);
            }
        }

        return $value;
    }

    /**
     * Checks whether unserialized data contains an incomplete (unloaded) class.
     *
     * @param mixed $data
     * @return bool
     */
    private function containsIncompleteClass($data): bool
    {
        if ($data instanceof \__PHP_Incomplete_Class) {
            return true;
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                if ($this->containsIncompleteClass($value)) {
                    return true;
                }
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                if ($this->containsIncompleteClass($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively replaces domains in nested arrays/objects.
     *
     * @param mixed $data Data to process
     * @return mixed Processed data
     */
    private function replaceInNestedData($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replaceInNestedData($value);
            }
        } elseif (is_object($data)) {
            if ($data instanceof \__PHP_Incomplete_Class) {
                return $data;
            }
            foreach (get_object_vars($data) as $key => $value) {
                $data->$key = $this->replaceInNestedData($value);
            }
        } elseif (is_string($data)) {
            foreach ($this->replacements as $search => $replace) {
                $data = str_replace($search, $replace, $data);
            }
        }

        return $data;
    }

    /**
     * Checks if a value is serialized.
     *
     * @param string $value Value to check
     * @return bool True if serialized
     */
    private function isSerialized(string $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ('N;' === $value) {
            return true;
        }

        if (!preg_match('/^([adObis]):/', $value, $matches)) {
            return false;
        }

        switch ($matches[1]) {
            case 'a':
            case 'O':
            case 's':
                if (preg_match("/^{$matches[1]}:[0-9]+:.*[;}]$/s", $value)) {
                    return true;
                }
                break;
            case 'b':
            case 'i':
            case 'd':
                if (preg_match("/^{$matches[1]}:[0-9.E-]+;$/", $value)) {
                    return true;
                }
                break;
        }

        return false;
    }
}


