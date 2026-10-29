<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\BrandingHelper;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

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
        define('PANELALPHA_MIGRATOR_MIGRATION_DIR', '/wp/wp-content/migrations/');
        define('PANELALPHA_MIGRATOR_PLUGIN_DIR', '/wp/wp-content/plugins/panelalpha-migrator/');
        define('PANELALPHA_MIGRATOR_PLUGIN_URL', 'https://example.com/wp-content/plugins/panelalpha-migrator/');
        define('PANELALPHA_MIGRATOR_VERSION', '1.2.0');
        Config::set('migration_dir', '/elsewhere/');

        $this->assertSame('/wp/wp-content/migrations/', PathProvider::getMigrationDir());
        $this->assertSame('/wp/wp-content/plugins/panelalpha-migrator/', PathProvider::getPluginDir());
        $this->assertSame('https://example.com/wp-content/plugins/panelalpha-migrator/', PathProvider::getPluginUrl());
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

    public function testTokenHelperReadsTheTokenAndSettingsFromOptions(): void
    {
        require __DIR__ . '/Support/OptionStubs.php';
        update_option(TokenHelper::TOKEN_OPTION, 'abc', false);
        update_option(TokenHelper::SETTINGS_OPTION, ['mode' => 'destination', 'show_buttons' => false]);

        $this->assertSame('abc', TokenHelper::getToken());
        $this->assertSame('destination', TokenHelper::getMode());
        $this->assertFalse(TokenHelper::getShowButtons());
    }

    public function testTokenHelperDefaultsWithoutOptions(): void
    {
        require __DIR__ . '/Support/OptionStubs.php';

        $this->assertNull(TokenHelper::getToken());
        $this->assertSame('source', TokenHelper::getMode());
        $this->assertTrue(TokenHelper::getShowButtons());
    }

    public function testBrandingComesFromBrandingFile(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'branding.php', "<?php return ['branding' => ['name' => 'Host Co', 'url' => 'https://host.example']];");
        define('PANELALPHA_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame(['name' => 'Host Co', 'url' => 'https://host.example'], BrandingHelper::getBranding());
    }

    public function testBrandingDefaultsToPanelAlpha(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'branding.php', "<?php return 'nope';");
        define('PANELALPHA_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame([], BrandingHelper::getConfig());
        $this->assertSame(['name' => 'PanelAlpha', 'url' => 'https://panelalpha.com'], BrandingHelper::getBranding());
    }

    public function testBrandingWithOnlyANameKeepsTheDefaultLink(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'branding.php', "<?php return ['branding' => ['name' => 'Host Co']];");
        define('PANELALPHA_MIGRATOR_PLUGIN_DIR', $pluginDir);

        $this->assertSame(['name' => 'Host Co', 'url' => 'https://panelalpha.com'], BrandingHelper::getBranding());
    }

    public function testConfigInitCollectsTheSiteSettings(): void
    {
        $abspath = $this->createTemporaryDirectory();
        mkdir($abspath . 'wp-includes');
        file_put_contents($abspath . 'wp-includes/version.php', "<?php \$wp_version = '6.8.1';");
        $pluginDir = $this->createTemporaryDirectory();
        require __DIR__ . '/Support/OptionStubs.php';
        update_option(TokenHelper::SETTINGS_OPTION, ['mode' => 'destination', 'show_buttons' => false]);
        define('ABSPATH', $abspath);
        define('PANELALPHA_MIGRATOR_PLUGIN_DIR', $pluginDir);
        define('PANELALPHA_MIGRATOR_PLUGIN_URL', 'https://old.example.com/wp-content/plugins/panelalpha-migrator/');
        define('PANELALPHA_MIGRATOR_MIGRATION_DIR', $abspath . 'wp-content/migrations/');
        define('PANELALPHA_MIGRATOR_VERSION', '1.2.0');
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
        define('PANELALPHA_MIGRATOR_PLUGIN_DIR', $this->createTemporaryDirectory());
        define('PANELALPHA_MIGRATOR_PLUGIN_URL', '');
        define('PANELALPHA_MIGRATOR_MIGRATION_DIR', '/tmp/migrations/');
        define('PANELALPHA_MIGRATOR_VERSION', '1.2.0');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'shop.example.com';

        Config::init();

        $this->assertSame('https://shop.example.com', Config::get('site_url'));
        $this->assertSame('https://shop.example.com', Config::get('home_url'));
        $this->assertSame('unknown', Config::get('wp_version'));
        $this->assertSame('source', Config::get('mode'));
    }
}
