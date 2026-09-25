<?php
/**
 * Plugin Name: WordPress Migrator
 * Description: A simple migration plugin for moving WordPress websites to PanelAlpha servers. Easily transfer your site files and database from your current hosting provider.
 * Version: 1.1.4
 * Author: PanelAlpha
 * Author URI: https://panelalpha.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wordpress-migrator
 */

if (!defined('ABSPATH')) {
    exit;
}


define('WP_MIGRATOR_VERSION', '1.1.4');
define('WP_MIGRATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_MIGRATOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_MIGRATOR_MIGRATION_DIR', WP_CONTENT_DIR . '/migrations/');

if (!file_exists(WP_MIGRATOR_PLUGIN_DIR . 'vendor/autoload.php')) {
    return;
}

require_once WP_MIGRATOR_PLUGIN_DIR . 'vendor/autoload.php';

\WordpressMigrator\Plugin::getInstance();
