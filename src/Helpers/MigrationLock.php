<?php

namespace PanelAlpha\Migrator\Helpers;

// The lock needs an exclusive create (fopen mode x) and an update under flock, which
// WP_Filesystem does not offer.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Migration lock manager using file-based locking.
 *
 * @package PanelAlpha\Migrator\Helpers
 */
class MigrationLock
{
    // The guard line version 1.2.1 put before the JSON. Only stripped when reading an old lock, never written.
    private const LEGACY_GUARD = "<?php exit; ?>\n";

    private ?string $lockFile;
    private ?string $legacyDir = null;
    private int $timeout;

    /**
     * @param string $lockFile Path to lock file
     * @param int $timeout Lock timeout in seconds (default: 15 minutes)
     */
    public function __construct(?string $lockFile = null, int $timeout = 900)
    {
        if ($lockFile === null) {
            $this->legacyDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : sys_get_temp_dir();
        }

        $this->lockFile = $lockFile;
        $this->timeout = $timeout;
    }

    /**
     * Name of the lock file in the migrations folder.
     * The folder also holds the migration manifests, so on a server that ignores .htaccess the name must not be guessable.
     *
     * @return string
     */
    public static function defaultFileName(): string
    {
        return function_exists('wp_hash') ? 'lock-' . substr(wp_hash('panelalpha-migrator-lock'), 0, 20) . '.json' : 'lock.json';
    }

    /**
     * Path of the lock file. The default name is resolved on first use, because the services
     * are built when the plugin loads, before wp_hash() exists.
     *
     * @return string
     */
    private function file(): string
    {
        if ($this->lockFile === null) {
            $this->lockFile = PathProvider::getMigrationDir() . self::defaultFileName();
        }

        return $this->lockFile;
    }

    /**
     * Check if a migration is currently running.
     *
     * @return bool True if a migration is running
     */
    public function isLocked(): bool
    {
        $this->adoptLegacyLock();

        if (!file_exists($this->file())) {
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

        $dir = dirname($this->file());
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        // Exclusive create: the second process loses even if both passed isLocked.
        $handle = @fopen($this->file(), 'x');

        if ($handle === false) {
            return false;
        }

        $lockData = array_merge([
            'migration_id' => $migrationId,
            'timestamp' => time(),
            'started_at' => gmdate('Y-m-d H:i:s'),
            'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
        ], $additionalData);

        $json = json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $written = $json !== false && fwrite($handle, $json) !== false;
        fclose($handle);

        if (!$written) {
            wp_delete_file($this->file());
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

        if (!file_exists($this->file())) {
            return true;
        }

        if ($migrationId !== null && !$this->isStale()) {
            $lock = $this->readLock();
            if (($lock['migration_id'] ?? null) !== $migrationId) {
                return false;
            }
        }

        $file = $this->file();
        wp_delete_file($file);

        return !file_exists($file);
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

        if (!is_file($this->file())) {
            throw new \RuntimeException('Migration lock is not held');
        }

        $handle = @fopen($this->file(), 'r+');
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
                fwrite($handle, $encoded);
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

        if (!file_exists($this->file())) {
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
            'updated_at' => gmdate('Y-m-d H:i:s'),
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
        if (!file_exists($this->file())) {
            return null;
        }

        $content = @file_get_contents($this->file());

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
     * Moves a lock written by version 1.2.1 or older (a .php file with a guard, or a .json file in
     * wp-content) to the lock file, so an upgrade during a migration does not allow a parallel start.
     * Nothing is written to a .php file.
     */
    private function adoptLegacyLock(): void
    {
        if ($this->legacyDir === null) {
            return;
        }

        foreach (['panelalpha-migrator-lock.php', 'panelalpha-migrator-lock.json'] as $name) {
            $legacy = $this->legacyDir . '/' . $name;
            if (!file_exists($legacy)) {
                continue;
            }

            if (!file_exists($this->file())) {
                $content = @file_get_contents($legacy);
                $dir = dirname($this->file());
                $handle = is_string($content) && (is_dir($dir) || wp_mkdir_p($dir)) ? @fopen($this->file(), 'x') : false;
                if ($handle !== false) {
                    fwrite($handle, strncmp($content, self::LEGACY_GUARD, strlen(self::LEGACY_GUARD)) === 0 ? substr($content, strlen(self::LEGACY_GUARD)) : $content);
                    fclose($handle);
                }
            }

            if (file_exists($this->file())) {
                wp_delete_file($legacy);
            }
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
        $dir = dirname($this->file());
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return false;
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($this->file(), $json, LOCK_EX);

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

        if (!file_exists($this->file())) {
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
        return $this->file();
    }
}
