<?php
/**
 * Runs when the plugin is deleted from the dashboard and removes all data the plugin stored.
 *
 * @package PanelAlpha\Migrator
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$panelalpha_migrator_autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($panelalpha_migrator_autoload)) {
    return;
}

require_once $panelalpha_migrator_autoload;

global $wpdb;

(new \PanelAlpha\Migrator\Uninstaller($wpdb, WP_CONTENT_DIR))->run();
