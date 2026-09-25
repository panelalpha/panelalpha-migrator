<?php

namespace WordpressMigrator\Admin;

use WordpressMigrator\Helpers\Config;

/**
 * Class AdminPage
 *
 * @package WordpressMigrator\Admin
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
