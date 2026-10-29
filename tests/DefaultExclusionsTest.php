<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Api\RouteProvider;
use PanelAlpha\Migrator\Helpers\DefaultExclusions;
use PanelAlpha\Migrator\Helpers\ExclusionParser;
use PanelAlpha\Migrator\Services\Scanner\FileExclusionService;

final class DefaultExclusionsTest extends TestCase
{
    private string $defaults = 'wp-config.php,panelalpha-migrator,wp-content/plugins/panelalpha-migrator,wordpress-migrator,wp-content/plugins/wordpress-migrator';

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

    public function testOnlyTheRootHtaccessIsExcludedByDefault(): void
    {
        $parsed = ExclusionParser::parse(DefaultExclusions::toNewlineFormat(DefaultExclusions::merge(null)));
        $defaults = RouteProvider::getDefaultExclusions();
        $exclusion = new FileExclusionService(
            array_merge($defaults['directories'], $parsed['directories']),
            $parsed['extensions'],
            array_merge($defaults['files'], $parsed['files'])
        );

        $this->assertTrue($exclusion->isExcluded('/site/.htaccess', '/site'));
        $this->assertTrue($exclusion->isExcluded('/site/wp-config.php', '/site'));
        $this->assertFalse($exclusion->isExcluded('/site/wp-content/uploads/woocommerce_uploads/.htaccess', '/site'));
        $this->assertFalse($exclusion->isExcluded('/site/wp-content/uploads/wc-logs/.htaccess', '/site'));
    }
}
