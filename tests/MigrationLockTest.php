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
        $this->lockFile = $this->directory . '/nested/wp-migrator-lock.php';
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

    public function testReleaseByAnotherMigrationIsRefusedUntilTimeout(): void
    {
        $lock = new MigrationLock($this->lockFile, 60);
        $lock->acquire('mig_1');

        $this->assertFalse($lock->release('mig_2'));
        $this->assertTrue($lock->isLocked());
        $this->assertSame('mig_1', $lock->getLockInfo()['migration_id']);

        $this->assertTrue($lock->release('mig_1'));
        $this->assertFalse($lock->isLocked());
    }

    public function testExpiredLockCanBeReleasedByTimeout(): void
    {
        $this->writeLockFile(['migration_id' => 'mig_old', 'timestamp' => time() - 120]);
        $lock = new MigrationLock($this->lockFile, 60);

        $this->assertTrue($lock->release('mig_other'));
        $this->assertFileDoesNotExist($this->lockFile);
    }

    public function testUpdateRefreshesTheActivityTimeout(): void
    {
        $this->writeLockFile(['migration_id' => 'mig_1', 'timestamp' => time() - 120]);
        $lock = new MigrationLock($this->lockFile, 60);

        $this->assertTrue($lock->update(['phase' => 'files']));

        $this->assertFalse($lock->isStale());
        $this->assertTrue($lock->isLocked());
        $this->assertSame('files', $lock->getLockInfo()['phase']);
    }

    public function testOnlyOneOfTwoSimultaneousAcquiresWins(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $command = [PHP_BINARY, '-r', 'require $argv[1]; $lock = new WordpressMigrator\Helpers\MigrationLock($argv[2]); echo $lock->acquire($argv[3]) ? "win" : "lose";', $autoload, $this->lockFile];
        $processes = [];
        $pipes = [];

        foreach (['mig_a', 'mig_b'] as $id) {
            $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processes[] = proc_open(array_merge($command, [$id]), $spec, $pipeSet);
            $pipes[] = $pipeSet;
        }

        $outcomes = [];
        foreach ($processes as $index => $process) {
            $outcomes[] = stream_get_contents($pipes[$index][1]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            proc_close($process);
        }

        sort($outcomes);
        $this->assertSame(['lose', 'win'], $outcomes);
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
        if (!is_dir(dirname($this->lockFile))) {
            mkdir(dirname($this->lockFile), 0755, true);
        }
        file_put_contents($this->lockFile, json_encode($data));
    }

    public function testDefaultExpiryIsFifteenMinutes(): void
    {
        $this->writeLockFile(['migration_id' => 'mig_1', 'timestamp' => time() - 800]);
        $this->assertTrue((new MigrationLock($this->lockFile))->isLocked());

        $this->writeLockFile(['migration_id' => 'mig_1', 'timestamp' => time() - 1000]);
        $this->assertFalse((new MigrationLock($this->lockFile))->isLocked());
    }

    public function testExclusiveExtendsTheExpiry(): void
    {
        $this->writeLockFile(['migration_id' => 'mig_1', 'timestamp' => time() - 800]);
        $lock = new MigrationLock($this->lockFile);

        $lock->exclusive('mig_1', static fn () => null);

        $this->assertGreaterThan(time() - 5, $lock->getLockInfo()['timestamp']);
    }

    public function testLockFileIsAPhpFileThatPrintsNothing(): void
    {
        $lock = new MigrationLock($this->lockFile);
        $lock->acquire('mig_1');
        $lock->update(['phase' => 'files']);

        $this->assertStringStartsWith("<?php exit; ?>\n", file_get_contents($this->lockFile));
        $this->assertSame('', (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->lockFile)));
        $this->assertSame('files', $lock->getLockInfo()['phase']);
    }

    public function testLockFromAnOlderVersionIsMovedAndStillHonored(): void
    {
        $content = sys_get_temp_dir() . '/wp-migrator-content-' . bin2hex(random_bytes(6));
        mkdir($content);
        $legacy = $content . '/wp-migrator-lock.json';
        file_put_contents($legacy, json_encode(['migration_id' => 'mig_old', 'timestamp' => time()]));

        $reflection = new \ReflectionClass(MigrationLock::class);
        $lock = $reflection->newInstanceWithoutConstructor();
        foreach (['lockFile' => $content . '/wp-migrator-lock.php', 'legacyLockFile' => $legacy, 'timeout' => 900] as $name => $value) {
            $property = $reflection->getProperty($name);
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            $property->setValue($lock, $value);
        }

        $this->assertTrue($lock->isLocked());
        $this->assertFalse($lock->acquire('mig_new'));
        $this->assertFileDoesNotExist($legacy);
        $this->assertSame('mig_old', $lock->getLockInfo()['migration_id']);

        $lock->release();
        @rmdir($content);
    }

    public function testJsonLockIsUsedWhenTheHostRefusesNewPhpFiles(): void
    {
        $content = sys_get_temp_dir() . '/wp-migrator-content-' . bin2hex(random_bytes(6));
        mkdir($content);
        // A dangling symlink: the .php lock does not exist and cannot be created, as on WP Engine.
        symlink($content . '/missing/target', $content . '/wp-migrator-lock.php');
        $legacy = $content . '/wp-migrator-lock.json';

        $reflection = new \ReflectionClass(MigrationLock::class);
        $lock = $reflection->newInstanceWithoutConstructor();
        foreach (['lockFile' => $content . '/wp-migrator-lock.php', 'legacyLockFile' => $legacy, 'timeout' => 900] as $name => $value) {
            $property = $reflection->getProperty($name);
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            $property->setValue($lock, $value);
        }

        $this->assertTrue($lock->acquire('mig_1'));
        $this->assertFileExists($legacy);
        $this->assertTrue($lock->isLocked());
        $this->assertFalse($lock->acquire('mig_2'));
        $this->assertSame('ran', $lock->exclusive('mig_1', fn() => 'ran'));
        $this->assertSame('mig_1', $lock->getLockInfo()['migration_id']);
        $this->assertTrue($lock->release('mig_1'));
        $this->assertFileDoesNotExist($legacy);
        $this->assertFalse($lock->isLocked());

        unlink($content . '/wp-migrator-lock.php');
        rmdir($content);
    }
}
