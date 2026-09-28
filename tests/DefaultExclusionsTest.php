<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\DefaultExclusions;

final class DefaultExclusionsTest extends TestCase
{
    private string $defaults = '.htaccess,wp-config.php,wordpress-migrator,wp-content/plugins/wordpress-migrator';

    public function testNoUserExclusionsGivesTheDefaults(): void
    {
        $this->assertSame($this->defaults, DefaultExclusions::merge(null));
        $this->assertSame($this->defaults, DefaultExclusions::merge(''));
    }

    public function testUserExclusionsAreAppendedAfterTheDefaults(): void
    {
        $result = DefaultExclusions::merge('*.log, node_modules');

        $this->assertSame($this->defaults . ',*.log,node_modules', $result);
    }

    public function testNewlinesSeparateUserExclusionsLikeCommas(): void
    {
        $result = DefaultExclusions::merge("*.log\ncache\n\n");

        $this->assertSame($this->defaults . ',*.log,cache', $result);
    }

    public function testUserExclusionThatRepeatsADefaultIsNotDuplicated(): void
    {
        $result = DefaultExclusions::merge('wp-config.php,*.log');

        $this->assertSame($this->defaults . ',*.log', $result);
    }

    public function testNewlineFormatPutsEachEntryOnItsOwnLine(): void
    {
        $result = DefaultExclusions::toNewlineFormat('.htaccess, *.log,,cache');

        $this->assertSame(".htaccess\n*.log\ncache", $result);
    }
}
