<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Restore\FilePathConfinement;

final class FilePathConfinementTest extends TestCase
{
    private FilePathConfinement $confinement;

    private string $wordpressDirectory = '/var/www/html';

    protected function setUp(): void
    {
        $this->confinement = new FilePathConfinement();
    }

    public function testOrdinaryFilePathIsReturnedCollapsedAndRelative(): void
    {
        $result = $this->confinement->confine('wp-content/uploads/a.jpg', $this->wordpressDirectory);

        $this->assertSame('wp-content/uploads/a.jpg', $result);
    }

    public function testCurrentDirectorySegmentDisappears(): void
    {
        $result = $this->confinement->confine('./wp-content/uploads/a.jpg', $this->wordpressDirectory);

        $this->assertSame('wp-content/uploads/a.jpg', $result);
    }

    public function testParentSegmentThatStaysInsideIsCollapsed(): void
    {
        $result = $this->confinement->confine('wp-content/../wp-content/uploads/a.jpg', $this->wordpressDirectory);

        $this->assertSame('wp-content/uploads/a.jpg', $result);
    }

    public function testParentSegmentThatClimbsOutIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File path is outside the WordPress directory');

        $this->confinement->confine('../outside.txt', $this->wordpressDirectory);
    }

    public function testEmptyFilePathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File path is outside the WordPress directory');

        $this->confinement->confine('', $this->wordpressDirectory);
    }

    public function testLeadingSlashIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File path is outside the WordPress directory');

        $this->confinement->confine('/etc/passwd', $this->wordpressDirectory);
    }

    public function testDrivePrefixIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File path is outside the WordPress directory');

        $this->confinement->confine('C:wp-content/a.jpg', $this->wordpressDirectory);
    }

    public function testPathThatCollapsesToWordPressDirectoryItselfIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File path is outside the WordPress directory');

        $this->confinement->confine('wp-content/../', $this->wordpressDirectory);
    }

    public function testBackslashStaysPartOfOneSegment(): void
    {
        $result = $this->confinement->confine('wp-content/foo\\bar.jpg', $this->wordpressDirectory);

        $this->assertSame('wp-content/foo\\bar.jpg', $result);
    }
}
