<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator;

use PanelAlpha\Migrator\Admin\AdminPage;
use PanelAlpha\Migrator\Api\RouteProvider;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\Filesystem;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Helpers\SetupFile;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Helpers\BrandingHelper;
use PanelAlpha\Migrator\Restore\ReceiveCleanup;

/**
 * Class Plugin
 *
 * @package PanelAlpha\Migrator
 *
 * Main plugin class that initializes and manages the PanelAlpha Migrator plugin.
 */
class Plugin
{
    private static ?self $instance = null;
    private RouteProvider $routeProvider;

    /**
     * Gets the singleton instance.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct()
    {
        SetupFile::import();
        Config::init();
        $this->createMigrationDirectory();
        $this->routeProvider = new RouteProvider();

        $this->initializeWordPress();
    }

    /**
     * Initializes WordPress hooks.
     */
    private function initializeWordPress(): void
    {
        if (function_exists('add_action')) {
            add_action('admin_menu', [$this, 'addAdminMenu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
            add_action('rest_api_init', [$this->routeProvider, 'registerWordPressRoutes']);
            add_action(ReceiveCleanup::HOOK, [ReceiveCleanup::class, 'runScheduled']);
            // The event is planned on the first load. A migration replaces the options table, so it is planned again after that.
            add_action('init', [ReceiveCleanup::class, 'schedule']);
            add_filter('plugin_row_meta', [$this, 'filterPluginRowMeta'], 10, 4);
        }
    }

    /**
     * Filters plugin row meta to apply dynamic branding.
     *
     * @param array<int, string>   $plugin_meta  An array of the plugin's metadata.
     * @param string               $plugin_file  Path to the plugin file relative to the plugins directory.
     * @param array<string, mixed> $plugin_data  An array of plugin data.
     * @param string               $status       Status of the plugin.
     *
     * @return array<int, string> Filtered plugin metadata.
     */
    public function filterPluginRowMeta(array $plugin_meta, string $plugin_file, array $plugin_data, string $status): array
    {
        if (!function_exists('plugin_basename')) {
            return $plugin_meta;
        }

        $thisPluginBasename = plugin_basename(PANELALPHA_MIGRATOR_PLUGIN_DIR . 'panelalpha-migrator.php');

        if ($plugin_file !== $thisPluginBasename) {
            return $plugin_meta;
        }

        // Without branding.php the row keeps the meta WordPress builds itself.
        if (BrandingHelper::getConfig() === []) {
            return $plugin_meta;
        }

        $branding = BrandingHelper::getBranding();

        $authorName = $branding['name'] ?? 'PanelAlpha';
        $authorUrl = $branding['url'] ?? 'https://panelalpha.com';
        $version = $plugin_data['Version'] ?? '';

        $newMeta = [];

        if ($version !== '') {
            $newMeta[] = sprintf(
                /* translators: %s: plugin version number */
                esc_html__('Version %s', 'panelalpha-migrator'),
                esc_html($version)
            );
        }

        $newMeta[] = sprintf(
            '%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_html__('By', 'panelalpha-migrator'),
            esc_url($authorUrl),
            esc_html($authorName)
        );

        return $newMeta;
    }

    /**
     * Creates the migration directory if it doesn't exist.
     */
    public function createMigrationDirectory(): void
    {
        $migrationDir = PathProvider::getMigrationDir();

        if (!file_exists($migrationDir)) {
            $this->ensureParentDirectoryIsWritable($migrationDir);
            $this->createDirectoryStructure($migrationDir);
        }

        if (!is_dir($migrationDir)) {
            return;
        }

        $this->createProtectionFiles($migrationDir);

        $collectedDir = $migrationDir . 'collected/';
        if (is_dir($collectedDir) || Filesystem::mkdirP($collectedDir)) {
            $this->createIndexFile($collectedDir);
        }
    }

    /**
     * Adds the files that keep the migration directory private, when missing.
     *
     * @param string $migrationDir Migration directory path
     */
    private function createProtectionFiles(string $migrationDir): void
    {
        $this->createHtaccessFile($migrationDir);
        $this->createIndexFile($migrationDir);
    }

    /**
     * Creates an empty index.php to stop directory listing.
     *
     * @param string $directory Directory path with trailing slash
     */
    private function createIndexFile(string $directory): void
    {
        $indexFile = $directory . 'index.php';

        if (file_exists($indexFile)) {
            return;
        }

        if (@file_put_contents($indexFile, "<?php\n// Silence is golden.\n") === false) {
            error_log("PanelAlpha Migrator: Failed to create index file: {$indexFile}");
        }
    }

    /**
     * Ensures the parent directory is writable.
     *
     * @param string $migrationDir Migration directory path
     */
    private function ensureParentDirectoryIsWritable(string $migrationDir): void
    {
        $parentDir = dirname($migrationDir);

        if (!wp_is_writable($parentDir)) {
            $errorMessage = "PanelAlpha Migrator: Parent directory is not writable: {$parentDir}";
            error_log($errorMessage);

            $this->showAdminNotice(
                'PanelAlpha Migrator: Cannot create migration directory. Please ensure ' .
                $parentDir . ' is writable.'
            );
        }
    }

    /**
     * Creates the directory structure.
     *
     * @param string $migrationDir Migration directory path
     */
    private function createDirectoryStructure(string $migrationDir): void
    {
        $result = Filesystem::mkdirP($migrationDir);

        if (!$result) {
            error_log("PanelAlpha Migrator: Failed to create migration directory: {$migrationDir}");
        }
    }

    /**
     * Creates .htaccess file to protect migration directory.
     *
     * @param string $migrationDir Migration directory path
     */
    private function createHtaccessFile(string $migrationDir): void
    {
        $htaccessFile = $migrationDir . '.htaccess';

        if (file_exists($htaccessFile) && trim((string) @file_get_contents($htaccessFile)) !== "Order deny,allow\nDeny from all") {
            return;
        }

        $htaccessContent = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";

        if (@file_put_contents($htaccessFile, $htaccessContent) === false) {
            error_log("PanelAlpha Migrator: Failed to create .htaccess file: {$htaccessFile}");
        }
    }

    /**
     * Shows an admin notice.
     *
     * @param string $message Notice message
     */
    private function showAdminNotice(string $message): void
    {
        if (function_exists('add_action')) {
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
        }
    }

    /**
     * Adds admin menu page.
     */
    public function addAdminMenu(): void
    {
        if (!function_exists('add_menu_page')) {
            return;
        }

        add_menu_page(
            __('PanelAlpha Migrator', 'panelalpha-migrator'),
            __('Migrator', 'panelalpha-migrator'),
            'manage_options',
            'panelalpha-migrator',
            [$this, 'renderAdminPage'],
            'dashicons-update',
            80
        );
    }

    /**
     * Renders the admin page.
     */
    public function renderAdminPage(): void
    {
        $adminPage = new AdminPage();
        $adminPage->render();
    }

    /**
     * Enqueues admin assets (CSS and JavaScript).
     *
     * @param string $hook Current admin page hook
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if ('toplevel_page_panelalpha-migrator' !== $hook) {
            return;
        }

        $this->enqueueStyles();
        $this->enqueueScripts();
    }

    /**
     * Enqueues admin styles.
     */
    private function enqueueStyles(): void
    {
        if (!function_exists('wp_enqueue_style')) {
            return;
        }

        wp_enqueue_style(
            'panelalpha-migrator-admin',
            PathProvider::getPluginUrl() . 'assets/css/migrator.css',
            [],
            PathProvider::getVersion()
        );
    }

    /**
     * Enqueues admin scripts with localized data.
     */
    private function enqueueScripts(): void
    {
        if (!function_exists('wp_enqueue_script') || !function_exists('wp_localize_script')) {
            return;
        }

        wp_enqueue_script(
            'panelalpha-migrator-admin',
            PathProvider::getPluginUrl() . 'assets/js/migrator.js',
            ['jquery'],
            PathProvider::getVersion(),
            true
        );

        wp_localize_script('panelalpha-migrator-admin', 'panelalphaMigrator', $this->getScriptData());
    }

    /**
     * Gets data to be localized for JavaScript.
     *
     * @return array Script data
     */
    private function getScriptData(): array
    {
        return [
            'ajaxUrl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            'restUrl' => function_exists('rest_url') ? rest_url('panelalpha-migrator/v1') : '',
            'nonce' => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
            'token' => TokenHelper::getToken() ?: '',
            'siteUrl' => function_exists('get_site_url') ? get_site_url() : Config::get('site_url', ''),
            'mode' => Config::get('mode', 'source'),
            'showModeSwitcher' => Config::get('show_mode_switcher', true),
            'i18n' => [
                'scanning' => __('Scanning in progress...', 'panelalpha-migrator'),
                'scanComplete' => __('Scan completed!', 'panelalpha-migrator'),
                'error' => __('An error occurred', 'panelalpha-migrator'),
                'enterUrl' => __('Enter destination WordPress URL', 'panelalpha-migrator'),
            ]
        ];
    }

    /**
     * Checks if current user has required permissions.
     *
     * @return bool True if user has permissions
     */
    public function checkPermissions(): bool
    {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }
}
