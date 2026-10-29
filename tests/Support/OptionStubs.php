<?php

declare(strict_types=1);

/**
 * Stand-ins for get_option(), update_option() and delete_option(), kept in $GLOBALS['wpm_options'].
 * Like WordPressStubs.php, require this file only from a test that runs in a separate process.
 */
$GLOBALS['wpm_options'] = [];
$GLOBALS['wpm_option_autoload'] = [];
// Set to true to make update_option() refuse every write.
$GLOBALS['wpm_options_read_only'] = false;

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['wpm_options'][$name] ?? $default;
    }

    function update_option($name, $value, $autoload = null)
    {
        if ($GLOBALS['wpm_options_read_only']) {
            return false;
        }

        $GLOBALS['wpm_options'][$name] = $value;
        $GLOBALS['wpm_option_autoload'][$name] = $autoload;

        return true;
    }

    function delete_option($name)
    {
        unset($GLOBALS['wpm_options'][$name]);

        return true;
    }
}
