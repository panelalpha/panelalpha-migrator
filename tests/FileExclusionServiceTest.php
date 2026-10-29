<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Services\Scanner\FileExclusionService;

final class FileExclusionServiceTest extends TestCase
{
    private FileExclusionService $exclusion;

    private string $rootPath = '/var/www/html';

    protected function setUp(): void
    {
        $this->exclusion = new FileExclusionService(
            ['cache', 'wp-content/uploads/panelalpha-migrator'],
            ['log', 'tmp'],
            ['error_log', 'wp-config.php']
        );
    }

    public function testFileUnderExcludedDirectoryIsExcluded(): void
    {
        $this->assertTrue(
            $this->exclusion->isExcluded(
                '/var/www/html/cache/file.txt',
                $this->rootPath
            )
        );
    }

    public function testFileNamedInExclusionListIsExcluded(): void
    {
        $this->assertTrue(
            $this->exclusion->isExcluded(
                '/var/www/html/error_log',
                $this->rootPath
            )
        );
    }

    public function testFileWithExcludedExtensionIsExcluded(): void
    {
        $this->assertTrue(
            $this->exclusion->isExcluded(
                '/var/www/html/wp-content/debug.log',
                $this->rootPath
            )
        );
    }

    public function testFileMatchingNoneOfExclusionListsIsKept(): void
    {
        $this->assertFalse(
            $this->exclusion->isExcluded(
                '/var/www/html/wp-content/uploads/photo.jpg',
                $this->rootPath
            )
        );
    }
}
