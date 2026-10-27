<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\BrandingHelper;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Helpers\TokenHelper;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

/**
 * Helpers that read WordPress and plugin constants. Each test runs in its own process,
 * because a constant cannot be undefined.
 *
 * @runTestsInSeparateProcesses
 */
#[RunTestsInSeparateProcesses]
final class WordPressEnvironmentTest extends TestCase
{
    use TemporaryDirectory;

    public function testPluginConstantsWinOverConfig(): void
    {
        define('WP_MIGRATOR_MIGRATION_DIR', '/wp/wp-content/migrations/');
        define('WP_MIGRATOR_PLUGIN_DIR', '/wp/wp-content/plugins/wordpress-migrator/');
        define('WP_MIGRATOR_PLUGIN_URL', 'https://example.com/wp-content/plugins/wordpress-migrator/');
        define('WP_MIGRATOR_VERSION', '1.2.0');
        Config::set('migration_dir', '/elsewhere/');

        $this->assertSame('/wp/wp-content/migrations/', PathProvider::getMigrationDir());
        $this->assertSame('/wp/wp-content/plugins/wordpress-migrator/', PathProvider::getPluginDir());
        $this->assertSame('https://example.com/wp-content/plugins/wordpress-migrator/', PathProvider::getPluginUrl());
        $this->assertSame('1.2.0', PathProvider::getVersion());
    }

    public function testOrdinarySiteUsesAbspath(): void
    {
        $abspath = $this->createTemporaryDirectory();
        mkdir($abspath . 'wp-content');
        define('ABSPATH', $abspath);
        define('WP_CONTENT_DIR', $abspath . 'wp-content');

        $this->assertSame($abspath, PathProvider::getAbsPath());
    }

    public function testWpCloudUsesTheParentOfWpContent(): void
    {
        $root = $this->createTemporaryDirectory();
        mkdir($root . 'wordpress/core', 0755, true);
        mkdir($root . 'srv/htdocs/wp-content', 0755, true);
        define('ABSPATH', $root . 'wordpress/core/');
        define('WP_CONTENT_DIR', $root . 'srv/htdocs/wp-content');

        $this->assertSame($root . 'srv/htdocs/', PathProvider::getAbsPath());
    }

    public function testBitnamiWpConfigSymlinkPointsToTheRealFolder(): void
    {
        $root = $this->createTemporaryDirectory();
        mkdir($root . 'opt/wordpress', 0755, true);
        mkdir($root . 'bitnami/wordpress', 0755, true);
        file_put_contents($root . 'bitnami/wordpress/wp-config.php', '<?php');
        symlink($root . 'bitnami/wordpress/wp-config.php', $root . 'opt/wordpress/wp-config.php');
        define('ABSPATH', $root . 'opt/wordpress/');

        $this->assertSame($root . 'bitnami/wordpress/', PathProvider::getAbsPath());
    }

    public function testBitnamiWpContentSymlinkPointsToTheRealFolder(): void
    {
        $root = $this->createTemporaryDirectory();
        mkdir($root . 'opt/wordpress', 0755, true);
        mkdir($root . 'bitnami/wordpress/wp-content', 0755, true);
        symlink($root . 'bitnami/wordpress/wp-content', $root . 'opt/wordpress/wp-content');
        define('ABSPATH', $root . 'opt/wordpress/');

        $this->assertSame($root . 'bitnami/wordpress/', PathProvider::getAbsPath());
    }

    public function testSymlinkInsideAbspathIsIgnored(): void
    {
        $root = $this->createTemporaryDirectory();
        mkdir($root . 'site/shared', 0755, true);
        file_put_contents($root . 'site/shared/wp-config.php', '<?php');
        symlink($root . 'site/shared/wp-config.php', $root . 'site/wp-config.php');
        define('ABSPATH', $root . 'site/');

        $this->assertSame($root . 'site/', PathProvider::getAbsPath());
    }

    public function testTokenHelperReadsConfigFromThePluginFolder(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'config.php', "<?php return ['token' => 'abc', 'mode' => 'destination', 'showButtons' => false];");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame('abc', TokenHelper::getToken());
        $this->assertSame('destination', TokenHelper::getMode());
        $this->assertFalse(TokenHelper::getShowButtons());
    }

    public function testTokenHelperDefaultsWithoutConfigFile(): void
    {
        define('WP_MIGRATOR_PLUGIN_DIR', $this->createTemporaryDirectory());

        $this->assertSame([], TokenHelper::getConfig());
        $this->assertNull(TokenHelper::getToken());
        $this->assertSame('source', TokenHelper::getMode());
        $this->assertTrue(TokenHelper::getShowButtons());
    }

    public function testTokenHelperIgnoresConfigThatIsNotAnArray(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'config.php', "<?php return 'nope';");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame([], TokenHelper::getConfig());
    }

    public function testBrandingComesFromBrandingFile(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'branding.php', "<?php return ['branding' => ['name' => 'Host Co', 'url' => 'https://host.example']];");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame(['name' => 'Host Co', 'url' => 'https://host.example'], BrandingHelper::getBranding());
    }

    public function testBrandingDefaultsToPanelAlpha(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'branding.php', "<?php return 'nope';");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame([], BrandingHelper::getConfig());
        $this->assertSame(['name' => 'PanelAlpha', 'url' => 'https://panelalpha.com'], BrandingHelper::getBranding());
    }

    public function testBrandingWithOnlyANameKeepsTheDefaultLink(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'branding.php', "<?php return ['branding' => ['name' => 'Host Co']];");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame(['name' => 'Host Co', 'url' => 'https://panelalpha.com'], BrandingHelper::getBranding());
    }

    public function testConfigInitCollectsTheSiteSettings(): void
    {
        $abspath = $this->createTemporaryDirectory();
        mkdir($abspath . 'wp-includes');
        file_put_contents($abspath . 'wp-includes/version.php', "<?php \$wp_version = '6.8.1';");
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'config.php', "<?php return ['mode' => 'destination', 'showButtons' => false];");
        define('ABSPATH', $abspath);
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);
        define('WP_MIGRATOR_PLUGIN_URL', 'https://old.example.com/wp-content/plugins/wordpress-migrator/');
        define('WP_MIGRATOR_MIGRATION_DIR', $abspath . 'wp-content/migrations/');
        define('WP_MIGRATOR_VERSION', '1.2.0');
        define('WP_SITEURL', 'https://old.example.com');
        define('WP_HOME', 'https://www.old.example.com');

        Config::init();

        $this->assertSame($pluginDir, Config::get('plugin_dir'));
        $this->assertSame($abspath . 'wp-content/migrations/', Config::get('migration_dir'));
        $this->assertSame('1.2.0', Config::get('version'));
        $this->assertSame('https://old.example.com', Config::get('site_url'));
        $this->assertSame('https://www.old.example.com', Config::get('home_url'));
        $this->assertSame('6.8.1', Config::get('wp_version'));
        $this->assertSame('https://old.example.com/wp-admin/', Config::get('admin_url'));
        $this->assertSame('https://old.example.com/wp-json/', Config::get('rest_url'));
        $this->assertSame('destination', Config::get('mode'));
        $this->assertFalse(Config::get('show_mode_switcher'));
    }

    public function testConfigInitBuildsTheSiteUrlFromTheRequest(): void
    {
        define('ABSPATH', $this->createTemporaryDirectory());
        define('WP_MIGRATOR_PLUGIN_DIR', $this->createTemporaryDirectory());
        define('WP_MIGRATOR_PLUGIN_URL', '');
        define('WP_MIGRATOR_MIGRATION_DIR', '/tmp/migrations/');
        define('WP_MIGRATOR_VERSION', '1.2.0');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'shop.example.com';

        Config::init();

        $this->assertSame('https://shop.example.com', Config::get('site_url'));
        $this->assertSame('https://shop.example.com', Config::get('home_url'));
        $this->assertSame('unknown', Config::get('wp_version'));
        $this->assertSame('source', Config::get('mode'));
    }
}
