<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\ExclusionParser;

final class ExclusionParserTest extends TestCase
{
    public function testStarDotEntryIsAnExtension(): void
    {
        $result = ExclusionParser::parse('*.log');

        $this->assertSame(['log'], $result['extensions']);
        $this->assertSame([], $result['directories']);
        $this->assertSame([], $result['files']);
    }

    public function testLeadingDotEntryIsAnExtensionAndAHiddenFolderOrFile(): void
    {
        $result = ExclusionParser::parse('.ddev');

        $this->assertSame(['ddev'], $result['extensions']);
        $this->assertSame(['.ddev'], $result['directories']);
    }

    public function testLeadingSlashIsDroppedFromADirectory(): void
    {
        $result = ExclusionParser::parse("/.ddev\n/wp-content/cache/");

        $this->assertSame(['.ddev', 'wp-content/cache'], $result['directories']);
        $this->assertSame([], $result['extensions']);
    }

    public function testDotFoldersAreLeftOutOfTheScan(): void
    {
        $result = ExclusionParser::parse(".ddev\n.git\n/node_modules");
        $exclusion = new \PanelAlpha\Migrator\Services\Scanner\FileExclusionService($result['directories'], $result['extensions'], $result['files']);

        $this->assertTrue($exclusion->isExcluded('/site/.ddev/config.yaml', '/site'));
        $this->assertTrue($exclusion->isExcluded('/site/wp-content/themes/x/.git/HEAD', '/site'));
        $this->assertTrue($exclusion->isExcluded('/site/node_modules/a.js', '/site'));
        $this->assertFalse($exclusion->isExcluded('/site/wp-content/uploads/my.ddev.jpg', '/site'));
        $this->assertFalse($exclusion->isExcluded('/site/wp-content/.gitkeep', '/site'));
    }

    public function testNameWithoutDotIsADirectory(): void
    {
        $result = ExclusionParser::parse('cache');

        $this->assertSame(['cache'], $result['directories']);
    }

    public function testPathWithSlashIsADirectoryWithoutTrailingSlash(): void
    {
        $result = ExclusionParser::parse('wp-content/cache/');

        $this->assertSame(['wp-content/cache'], $result['directories']);
    }

    public function testBackslashesBecomeSlashesInADirectory(): void
    {
        $result = ExclusionParser::parse('wp-content\\uploads\\old\\');

        $this->assertSame(['wp-content/uploads/old'], $result['directories']);
    }

    public function testPathWithSlashAndDotIsStillADirectory(): void
    {
        $result = ExclusionParser::parse('wp-content/uploads/backup.old');

        $this->assertSame(['wp-content/uploads/backup.old'], $result['directories']);
        $this->assertSame([], $result['files']);
    }

    public function testNameWithDotIsAFile(): void
    {
        $result = ExclusionParser::parse('error_log.txt');

        $this->assertSame(['error_log.txt'], $result['files']);
    }

    public function testEachLineIsParsedAndBlankLinesAreSkipped(): void
    {
        $result = ExclusionParser::parse("  *.log  \n\n\r\nnode_modules\r\nreadme.html\n");

        $this->assertSame([
            'directories' => ['node_modules'],
            'extensions' => ['log'],
            'files' => ['readme.html'],
        ], $result);
    }

    public function testCommaSeparatedEntriesOnOneLineAreOneEntry(): void
    {
        $result = ExclusionParser::parse('*.log,node_modules');

        $this->assertSame(['log,node_modules'], $result['extensions']);
        $this->assertSame([], $result['directories']);
    }
}
