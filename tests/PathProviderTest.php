<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Tests\Support\ResetsGlobalState;

/**
 * Outside WordPress, where none of the plugin constants are defined.
 */
final class PathProviderTest extends TestCase
{
    use ResetsGlobalState;

    public function testPathsComeFromConfig(): void
    {
        Config::set('migration_dir', '/data/migrations/');
        Config::set('plugin_dir', '/data/plugin/');
        Config::set('plugin_url', 'https://example.com/plugin/');
        Config::set('root_path', '/data/site/');
        Config::set('version', '9.9.9');

        $this->assertSame('/data/migrations/', PathProvider::getMigrationDir());
        $this->assertSame('/data/plugin/', PathProvider::getPluginDir());
        $this->assertSame('https://example.com/plugin/', PathProvider::getPluginUrl());
        $this->assertSame('/data/site/', PathProvider::getAbsPath());
        $this->assertSame('9.9.9', PathProvider::getVersion());
        $this->assertSame('/data/migrations/mig_1.json', PathProvider::getMigrationFilePath('mig_1'));
        $this->assertSame('/data/migrations/mig_20261009_163045.json', PathProvider::getMigrationFilePath('mig_20261009_163045'));
        $this->assertSame('/data/migrations/collected/', PathProvider::getCollectedDir());
    }

    public function testTraversalMigrationIdIsRejected(): void
    {
        Config::set('migration_dir', '/data/migrations/');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid migration ID');

        PathProvider::getMigrationFilePath('../outside');
    }

    public function testDefaultsWithoutConfig(): void
    {
        $pluginDir = dirname(__DIR__) . '/';

        $this->assertSame($pluginDir, PathProvider::getPluginDir());
        $this->assertSame($pluginDir . 'migrations/', PathProvider::getMigrationDir());
        $this->assertSame('', PathProvider::getPluginUrl());
        $this->assertSame(getcwd() . '/', PathProvider::getAbsPath());
        $this->assertSame('1.0.0', PathProvider::getVersion());
    }

    public function testConfigValuesCanBeReadWithADefault(): void
    {
        Config::set('answer', 42);

        $this->assertSame(42, Config::get('answer'));
        $this->assertSame('fallback', Config::get('missing', 'fallback'));
        $this->assertNull(Config::get('missing'));
    }

    public function testTrailingNewlineIsNotASafeIdentifier(): void
    {
        $this->assertFalse(PathProvider::isSafeMigrationId("mig_1\n"));
        $this->assertFalse(PathProvider::isSafeChunkToken("file_chunk_1\n"));
        $this->assertTrue(PathProvider::isSafeMigrationId('mig_1'));
        $this->assertTrue(PathProvider::isSafeChunkToken('file-chunk_1'));
    }
}
