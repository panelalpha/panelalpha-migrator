<?php

namespace WordpressMigrator\Helpers;

/**
 * Migration lock manager using file-based locking.
 *
 * @package WordpressMigrator\Helpers
 */
class MigrationLock
{
    private string $lockFile;
    private int $timeout;

    /**
     * @param string $lockFile Path to lock file
     * @param int $timeout Lock timeout in seconds (default: 2 hours)
     */
    public function __construct(string $lockFile = null, int $timeout = 7200)
    {
        if ($lockFile === null) {
            $lockFile = defined('WP_CONTENT_DIR')
                ? WP_CONTENT_DIR . '/wp-migrator-lock.json'
                : sys_get_temp_dir() . '/wp-migrator-lock.json';
        }

        $this->lockFile = $lockFile;
        $this->timeout = $timeout;
    }

    /**
     * Check if a migration is currently running.
     *
     * @return bool True if a migration is running
     */
    public function isLocked(): bool
    {
        if (!file_exists($this->lockFile)) {
            return false;
        }

        $lock = $this->readLock();

        if (!$lock) {
            return false;
        }

        $lockTime = $lock['timestamp'] ?? 0;
        if (time() - $lockTime > $this->timeout) {
            $this->release();
            return false;
        }

        return true;
    }

    /**
     * Acquire a lock for a migration.
     *
     * @param string $migrationId Migration ID
     * @param array $additionalData Additional data to store in lock
     * @return bool True if lock was acquired, false if already locked
     */
    public function acquire(string $migrationId, array $additionalData = []): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        $lockData = array_merge([
            'migration_id' => $migrationId,
            'timestamp' => time(),
            'started_at' => date('Y-m-d H:i:s'),
            'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
        ], $additionalData);

        return $this->writeLock($lockData);
    }

    /**
     * Release the lock.
     *
     * @return bool True if lock was released
     */
    public function release(): bool
    {
        if (!file_exists($this->lockFile)) {
            return true;
        }

        return @unlink($this->lockFile);
    }

    /**
     * Get information about the current lock.
     *
     * @return array|null Lock data or null if no lock exists
     */
    public function getLockInfo(): ?array
    {
        if (!file_exists($this->lockFile)) {
            return null;
        }

        return $this->readLock();
    }

    /**
     * Update lock data (e.g., to update status).
     *
     * @param array $data Data to merge with existing lock
     * @return bool True on success
     */
    public function update(array $data): bool
    {
        $lock = $this->readLock();

        if (!$lock) {
            return false;
        }

        $updatedLock = array_merge($lock, $data, [
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this->writeLock($updatedLock);
    }

    /**
     * Read lock file contents.
     *
     * @return array|null Lock data or null on failure
     */
    private function readLock(): ?array
    {
        if (!file_exists($this->lockFile)) {
            return null;
        }

        $content = @file_get_contents($this->lockFile);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Write lock file.
     *
     * @param array $data Lock data to write
     * @return bool True on success
     */
    private function writeLock(array $data): bool
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return false;
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($this->lockFile, $json, LOCK_EX);

        return $result !== false;
    }

    /**
     * Force release lock (use with caution).
     *
     * @return bool True if lock was released
     */
    public function forceRelease(): bool
    {
        return $this->release();
    }

    /**
     * Check if lock is stale (expired but not cleaned up).
     *
     * @return bool True if lock exists but is expired
     */
    public function isStale(): bool
    {
        if (!file_exists($this->lockFile)) {
            return false;
        }

        $lock = $this->readLock();

        if (!$lock) {
            return true;
        }

        $lockTime = $lock['timestamp'] ?? 0;
        return (time() - $lockTime > $this->timeout);
    }

    /**
     * Get lock file path.
     *
     * @return string Lock file path
     */
    public function getLockFilePath(): string
    {
        return $this->lockFile;
    }
}
