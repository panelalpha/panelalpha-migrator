<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\ExclusionParser;

final class ExclusionParserTest extends TestCase
{
    public function testStarDotEntryIsAnExtension(): void
    {
        $result = ExclusionParser::parse('*.log');

        $this->assertSame(['log'], $result['extensions']);
        $this->assertSame([], $result['directories']);
        $this->assertSame([], $result['files']);
    }

    public function testLeadingDotEntryIsAnExtension(): void
    {
        $result = ExclusionParser::parse('.htaccess');

        $this->assertSame(['htaccess'], $result['extensions']);
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
    }
}
