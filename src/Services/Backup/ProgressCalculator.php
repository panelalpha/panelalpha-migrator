<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Backup;

/**
 * Trait ProgressCalculator
 *
 * @package WordpressMigrator\Services\Backup
 *
 * Provides common progress calculation methods for backup services.
 */
trait ProgressCalculator
{
    /**
     * Calculates the percentage of completion.
     *
     * @param int $total Total number of items
     * @param int $completed Number of completed items
     * @return float Percentage (0-100)
     */
    protected function calculatePercentage(int $total, int $completed): float
    {
        return $total > 0 ? round(($completed / $total) * 100, 2) : 0;
    }

    /**
     * Counts the number of sent chunks in a chunk array.
     *
     * @param array $chunks Array of chunks
     * @return int Number of sent chunks
     */
    protected function countSentChunks(array $chunks): int
    {
        $count = 0;

        foreach ($chunks as $chunk) {
            if ($chunk['status'] === 'sent') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Checks if all chunks in an array have been sent.
     *
     * @param array $chunks Array of chunks
     * @return bool True if all chunks are sent
     */
    protected function areAllChunksSent(array $chunks): bool
    {
        foreach ($chunks as $chunk) {
            if ($chunk['status'] !== 'sent') {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculates the sent size from chunks.
     *
     * @param array $chunks Array of chunks with size information
     * @return int Total size of sent chunks
     */
    protected function calculateSentSize(array $chunks): int
    {
        $sentSize = 0;

        foreach ($chunks as $chunk) {
            if ($chunk['status'] === 'sent') {
                $sentSize += $chunk['size'] ?? 0;
            }
        }

        return $sentSize;
    }

    /**
     * Determines the current item being processed from an array.
     *
     * @param array $items Array of items (tables or files)
     * @return string|null Name/path of current item or null if none found
     */
    protected function findCurrentItem(array $items): ?string
    {
        foreach ($items as $item) {
            if ($item['status'] === 'in_progress') {
                return $item['name'] ?? $item['path'] ?? null;
            }
        }


        foreach ($items as $item) {
            if ($item['status'] === 'pending') {
                return $item['name'] ?? $item['path'] ?? null;
            }
        }

        return null;
    }
}



