<?php

namespace WordpressMigrator\Helpers;

/**
 * Migration lock manager using file-based locking.
 *
 * @package WordpressMigrator\Helpers
 */
class MigrationLock
{
    private const GUARD = "<?php exit; ?>\n";

    private string $lockFile;
    private ?string $legacyLockFile = null;
    private int $timeout;

    /**
     * @param string $lockFile Path to lock file
     * @param int $timeout Lock timeout in seconds (default: 15 minutes)
     */
    public function __construct(?string $lockFile = null, int $timeout = 900)
    {
        if ($lockFile === null) {
            $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : sys_get_temp_dir();
            $lockFile = $base . '/wp-migrator-lock.php';
            $this->legacyLockFile = $base . '/wp-migrator-lock.json';
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
        $this->adoptLegacyLock();

        if (!file_exists($this->activeFile())) {
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
        $this->adoptLegacyLock();

        if ($this->isLocked()) {
            return false;
        }

        $dir = dirname($this->lockFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }

        // Exclusive create: the second process loses even if both passed isLocked.
        $lockFile = $this->lockFile;
        $handle = @fopen($lockFile, 'x');

        // Hosts such as WP Engine refuse new .php files; then the lock is the JSON file older versions used.
        if ($handle === false && !file_exists($lockFile) && $this->legacyLockFile !== null) {
            $lockFile = $this->legacyLockFile;
            $handle = @fopen($lockFile, 'x');
        }

        if ($handle === false) {
            return false;
        }

        $lockData = array_merge([
            'migration_id' => $migrationId,
            'timestamp' => time(),
            'started_at' => date('Y-m-d H:i:s'),
            'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
        ], $additionalData);

        $json = json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $written = $json !== false && fwrite($handle, self::GUARD . $json) !== false;
        fclose($handle);

        if (!$written) {
            @unlink($lockFile);
            return false;
        }

        return true;
    }

    /**
     * Release the lock.
     *
     * @param string|null $migrationId When set, the lock is released only if this migration holds it
     * @return bool True if lock was released
     */
    public function release(?string $migrationId = null): bool
    {
        $this->adoptLegacyLock();

        if (!file_exists($this->activeFile())) {
            return true;
        }

        if ($migrationId !== null && !$this->isStale()) {
            $lock = $this->readLock();
            if (($lock['migration_id'] ?? null) !== $migrationId) {
                return false;
            }
        }

        return @unlink($this->activeFile());
    }

    /**
     * Runs one mutation while this migration owns the lock.
     * A second caller gets a conflict instead of mutating at the same time.
     *
     * @param string   $migrationId
     * @param callable $callback
     * @return mixed
     */
    public function exclusive(string $migrationId, callable $callback)
    {
        $this->adoptLegacyLock();

        if (!is_file($this->activeFile())) {
            throw new \RuntimeException('Migration lock is not held');
        }

        $handle = @fopen($this->activeFile(), 'r+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Migration lock is busy');
        }

        try {
            $lock = $this->readLock();
            if (($lock['migration_id'] ?? null) !== $migrationId) {
                throw new \RuntimeException('Migration lock is held by another migration');
            }

            $lock['timestamp'] = time();
            $encoded = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, self::GUARD . $encoded);
                fflush($handle);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Get information about the current lock.
     *
     * @return array|null Lock data or null if no lock exists
     */
    public function getLockInfo(): ?array
    {
        $this->adoptLegacyLock();

        if (!file_exists($this->activeFile())) {
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
        $this->adoptLegacyLock();

        $lock = $this->readLock();

        if (!$lock) {
            return false;
        }

        $updatedLock = array_merge($lock, $data, [
            'updated_at' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
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
        if (!file_exists($this->activeFile())) {
            return null;
        }

        $content = @file_get_contents($this->activeFile());

        if ($content === false) {
            return null;
        }

        if (strncmp($content, self::GUARD, strlen(self::GUARD)) === 0) {
            $content = substr($content, strlen(self::GUARD));
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * The guarded .php lock, or the JSON lock when only that one exists:
     * written by an older version, or on a host that refuses new .php files.
     */
    private function activeFile(): string
    {
        if ($this->legacyLockFile !== null && !file_exists($this->lockFile) && file_exists($this->legacyLockFile)) {
            return $this->legacyLockFile;
        }

        return $this->lockFile;
    }

    /**
     * Moves a lock written by an older version (public JSON file) to the guarded file,
     * so an upgrade during a migration does not allow a parallel start.
     * When the host refuses the .php file, the JSON lock stays in use.
     */
    private function adoptLegacyLock(): void
    {
        if ($this->legacyLockFile === null || !file_exists($this->legacyLockFile)) {
            return;
        }

        if (!file_exists($this->lockFile)) {
            $content = @file_get_contents($this->legacyLockFile);
            $handle = is_string($content) ? @fopen($this->lockFile, 'x') : false;
            if ($handle !== false) {
                fwrite($handle, self::GUARD . $content);
                fclose($handle);
            }
        }

        if (file_exists($this->lockFile)) {
            @unlink($this->legacyLockFile);
        }
    }

    /**
     * Write lock file.
     *
     * @param array $data Lock data to write
     * @return bool True on success
     */
    private function writeLock(array $data): bool
    {
        $dir = dirname($this->activeFile());
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return false;
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($this->activeFile(), self::GUARD . $json, LOCK_EX);

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
        $this->adoptLegacyLock();

        if (!file_exists($this->activeFile())) {
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
