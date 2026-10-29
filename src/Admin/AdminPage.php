<?php

namespace PanelAlpha\Migrator\Admin;

use PanelAlpha\Migrator\Helpers\Config;

/**
 * Class AdminPage
 *
 * @package PanelAlpha\Migrator\Admin
 *
 * This class is responsible for rendering the admin page of the plugin.
 */
class AdminPage
{
    /**
     * Renders the admin page template.
     */
    public function render()
    {
        $pluginDir = Config::get('plugin_dir');
        require $pluginDir . 'templates/admin-page.php';
    }
}
