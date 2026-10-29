<?php

declare(strict_types=1);

// Stand-in for WP_Filesystem_Direct. The real class comes with WordPress, which tests do not load.
// Each method does what the real one does for the calls the plugin makes.
if (!class_exists('WP_Filesystem_Direct', false)) {
    class WP_Filesystem_Direct
    {
        public function __construct($arg = null)
        {
        }

        public function rmdir($path, $recursive = false)
        {
            return @rmdir($path);
        }

        public function chmod($file, $mode = false, $recursive = false)
        {
            return @chmod($file, $mode);
        }

        public function touch($file, $time = 0, $atime = 0)
        {
            return @touch($file, $time, $atime ?: $time);
        }

        public function move($source, $destination, $overwrite = false)
        {
            if (!$overwrite && file_exists($destination)) {
                return false;
            }

            return @rename($source, $destination);
        }
    }
}
