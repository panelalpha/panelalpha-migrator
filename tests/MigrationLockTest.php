<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\MigrationLock;

final class MigrationLockTest extends TestCase
{
    private string $directory;

    private string $lockFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wp-migrator-lock-test-' . bin2hex(random_bytes(6));
        $this->lockFile = $this->directory . '/nested/wp-migrator-lock.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockFile);
        @rmdir(dirname($this->lockFile));
        @rmdir($this->directory);
    }

    public function testNoLockFileMeansNotLocked(): void
    {
        $lock = new MigrationLock($this->lockFile);

        $this->assertFalse($lock->isLocked());
        $this->assertFalse($lock->isStale());
        $this->assertNull($lock->getLockInfo());
    }

    public function testAcquireWritesTheMigrationIdAndCreatesTheFolder(): void
    {
        $lock = new MigrationLock($this->lockFile);

        $this->assertTrue($lock->acquire('mig_1', ['source' => 'api']));

        $this->assertTrue($lock->isLocked());
        $info = $lock->getLockInfo();
        $this->assertSame('mig_1', $info['migration_id']);
        $this->assertSame('api', $info['source']);
        $this->assertArrayHasKey('timestamp', $info);
        $this->assertArrayHasKey('pid', $info);
    }

    public function testSecondAcquireIsRefusedWhileLocked(): void
    {
        $lock = new MigrationLock($this->lockFile);
        $lock->acquire('mig_1');

        $this->assertFalse($lock->acquire('mig_2'));
        $this->assertSame('mig_1', $lock->getLockInfo()['migration_id']);
    }

    public function testReleaseRemovesTheLock(): void
    {
        $lock = new MigrationLock($this->lockFile);
        $lock->acquire('mig_1');

        $this->assertTrue($lock->release());

        $this->assertFalse($lock->isLocked());
        $this->assertFileDoesNotExist($this->lockFile);
        $this->assertTrue($lock->acquire('mig_2'));
    }

    public function testExpiredLockIsStaleAndReleasedOnCheck(): void
    {
        $this->writeLockFile(['migration_id' => 'mig_old', 'timestamp' => time() - 7201]);
        $lock = new MigrationLock($this->lockFile);

        $this->assertTrue($lock->isStale());
        $this->assertFalse($lock->isLocked());
        $this->assertFileDoesNotExist($this->lockFile);
    }

    public function testLockWithinTimeoutIsNotStale(): void
    {
        $this->writeLockFile(['migration_id' => 'mig_1', 'timestamp' => time() - 60]);
        $lock = new MigrationLock($this->lockFile);

        $this->assertFalse($lock->isStale());
        $this->assertTrue($lock->isLocked());
    }

    public function testUnreadableLockFileIsStaleButNotLocked(): void
    {
        mkdir(dirname($this->lockFile), 0755, true);
        file_put_contents($this->lockFile, 'not json');
        $lock = new MigrationLock($this->lockFile);

        $this->assertTrue($lock->isStale());
        $this->assertFalse($lock->isLocked());
    }

    public function testUpdateMergesDataIntoAnExistingLock(): void
    {
        $lock = new MigrationLock($this->lockFile);
        $lock->acquire('mig_1');

        $this->assertTrue($lock->update(['phase' => 'files']));

        $info = $lock->getLockInfo();
        $this->assertSame('mig_1', $info['migration_id']);
        $this->assertSame('files', $info['phase']);
        $this->assertArrayHasKey('updated_at', $info);
    }

    public function testUpdateWithoutALockFails(): void
    {
        $lock = new MigrationLock($this->lockFile);

        $this->assertFalse($lock->update(['phase' => 'files']));
        $this->assertFileDoesNotExist($this->lockFile);
    }

    private function writeLockFile(array $data): void
    {
        mkdir(dirname($this->lockFile), 0755, true);
        file_put_contents($this->lockFile, json_encode($data));
    }
}
