<?php
/**
 * Plugin Name: PanelAlpha Migrator
 * Description: A simple migration plugin for moving WordPress websites to PanelAlpha servers. Easily transfer your site files and database from your current hosting provider.
 * Version: 1.2.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: PanelAlpha
 * Author URI: https://panelalpha.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: panelalpha-migrator
 */

if (!defined('ABSPATH')) {
    exit;
}


define('PANELALPHA_MIGRATOR_VERSION', '1.2.1');
define('PANELALPHA_MIGRATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PANELALPHA_MIGRATOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PANELALPHA_MIGRATOR_MIGRATION_DIR', WP_CONTENT_DIR . '/migrations/');

if (!file_exists(PANELALPHA_MIGRATOR_PLUGIN_DIR . 'vendor/autoload.php')) {
    return;
}

require_once PANELALPHA_MIGRATOR_PLUGIN_DIR . 'vendor/autoload.php';

register_deactivation_hook(__FILE__, ['PanelAlpha\\Migrator\\Restore\\ReceiveCleanup', 'unschedule']);

\PanelAlpha\Migrator\Plugin::getInstance();
