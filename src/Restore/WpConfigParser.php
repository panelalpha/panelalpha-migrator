<?php

namespace WordpressMigrator\Restore;

use WordpressMigrator\Helpers\TokenHelper;
use WordpressMigrator\Helpers\PathProvider;

class WpConfigParser
{
    private string $configPath;
    private string $abspath;
    private bool $wpLoaded = false;

    public function __construct(string $abspath)
    {
        $this->abspath = $abspath;
        $this->configPath = $abspath . 'wp-config.php';
    }

    public function parse(): array
    {
        $config = $this->tryGetFromDefinedConstants();

        if ($this->isValidConfig($config)) {
            return $this->normalizeHost($config);
        }

        $config = $this->tryExtractFromWpConfig();

        if ($this->isValidConfig($config)) {
            return $this->normalizeHost($config);
        }

        $config = $this->tryGetFromManualConfig();

        if ($this->isValidConfig($config)) {
            return $this->normalizeHost($config);
        }

        $config = $this->tryLoadFromWpLoad();

        if ($this->isValidConfig($config)) {
            return $this->normalizeHost($config);
        }

        throw new \RuntimeException('Could not parse database credentials from wp-config.php');
    }

    private function tryGetFromDefinedConstants(): array
    {
        if (!defined('DB_NAME') || !defined('DB_USER') || !defined('DB_HOST')) {
            return [];
        }

        return [
            'db_name' => DB_NAME,
            'db_user' => DB_USER,
            'db_password' => defined('DB_PASSWORD') ? DB_PASSWORD : '',
            'db_host' => DB_HOST,
            'table_prefix' => $this->getTablePrefix(),
        ];
    }

    /**
     * Fallback for platforms that don't expose DB_* as PHP constants (or expose them indirectly).
     *
     * This relies on WordPress being loaded (wp-load.php), so it is only useful after bootstrap.
     */
    private function tryGetFromWpdb(): array
    {
        global $wpdb, $table_prefix;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return [];
        }

        $dbName = defined('DB_NAME') ? DB_NAME : ($wpdb->dbname ?? '');
        $dbUser = defined('DB_USER') ? DB_USER : ($wpdb->dbuser ?? '');
        $dbPassword = defined('DB_PASSWORD') ? DB_PASSWORD : ($wpdb->dbpassword ?? '');
        $dbHost = defined('DB_HOST') ? DB_HOST : ($wpdb->dbhost ?? '');

        // Preserve an explicitly empty prefix (e.g. "$table_prefix = ''") if WP provides it.
        // Only fall back to 'wp_' when the prefix is truly unavailable.
        $prefix = $table_prefix ?? ($wpdb->prefix ?? null);

        return [
            'db_name' => (string) $dbName,
            'db_user' => (string) $dbUser,
            'db_password' => (string) $dbPassword,
            'db_host' => (string) $dbHost,
            'table_prefix' => $prefix === null ? 'wp_' : (string) $prefix,
        ];
    }

    private function tryLoadFromWpLoad(): array
    {
        $wpLoadPath = $this->abspath . 'wp-load.php';

        if (!file_exists($wpLoadPath)) {
            return [];
        }


        if (!defined('DOING_CRON')) {
            define('DOING_CRON', true);
        }
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        // Stop wp-settings.php before the installed check, which dies when the tables
        // are dropped in the middle of a migration.
        if (!defined('SHORTINIT')) {
            define('SHORTINIT', true);
        }

        try {
            ob_start();
            @require_once $wpLoadPath;
            ob_end_clean();
            $this->wpLoaded = true;
        } catch (\Throwable $e) {
            ob_end_clean();
            return [];
        }

        $config = $this->tryGetFromDefinedConstants();
        if ($this->isValidConfig($config)) {
            return $config;
        }

        return $this->tryGetFromWpdb();
    }

    private function tryExtractFromWpConfig(): array
    {
        $configPath = $this->configPath;
        if (!file_exists($configPath)) {
            // Try with PathProvider which handles WP Cloud and Bitnami
            $alternativePath = PathProvider::getAbsPath() . 'wp-config.php';
            if (!file_exists($alternativePath)) {
                return [];
            }
            $configPath = $alternativePath;
        }

        $content = file_get_contents($configPath);

        return [
            'db_name' => $this->extractConstant($content, 'DB_NAME'),
            'db_user' => $this->extractConstant($content, 'DB_USER'),
            'db_password' => $this->extractConstant($content, 'DB_PASSWORD'),
            'db_host' => $this->extractConstant($content, 'DB_HOST', 'localhost'),
            'table_prefix' => $this->getTablePrefix($content),
        ];
    }

    private function tryGetFromManualConfig(): array
    {
        $pluginConfig = TokenHelper::getConfig();
        $db = $pluginConfig['database'] ?? [];

        if (empty($db)) {
            return [];
        }

        return [
            'db_name' => $db['db_name'] ?? '',
            'db_user' => $db['db_user'] ?? '',
            'db_password' => $db['db_password'] ?? '',
            'db_host' => $db['db_host'] ?? 'localhost',
            'table_prefix' => $this->getTablePrefix(),
        ];
    }

    /**
     * Resolves the table prefix: the literal in wp-config.php, then the manual
     * config.php value, then the global set by a loaded WordPress, then the WordPress default.
     */
    private function getTablePrefix(?string $wpConfigContent = null): string
    {
        global $table_prefix;
        if ($this->wpLoaded && is_string($table_prefix)) {
            return $table_prefix;
        }

        $content = $wpConfigContent ?? $this->readWpConfig();
        $prefix = $content === null ? null : $this->extractTablePrefix($content);
        if ($prefix !== null) {
            return $prefix;
        }

        $manual = TokenHelper::getConfig()['database']['table_prefix'] ?? null;
        if (is_string($manual)) {
            return $manual;
        }

        if (is_string($table_prefix) && $table_prefix !== '') {
            return $table_prefix;
        }

        return 'wp_';
    }

    private function readWpConfig(): ?string
    {
        $configPath = $this->configPath;
        if (!file_exists($configPath)) {
            // Try with PathProvider which handles WP Cloud and Bitnami
            $alternativePath = PathProvider::getAbsPath() . 'wp-config.php';
            if (!file_exists($alternativePath)) {
                return null;
            }
            $configPath = $alternativePath;
        }

        $content = file_get_contents($configPath);

        return is_string($content) ? $content : null;
    }

    private function isValidConfig(array $config): bool
    {
        return !empty($config['db_name']) && !empty($config['db_user']) && !empty($config['db_host']);
    }

    private function normalizeHost(array $config): array
    {
        if ($config['db_host'] === 'localhost' && PHP_OS_FAMILY !== 'Windows') {
            $config['db_host'] = '127.0.0.1';
        }

        return $config;
    }

    private function extractConstant(string $content, string $name, string $default = ''): string
    {
        $pattern = "/define\\s*\\(\\s*['\"]" . preg_quote($name, '/') . "['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/";

        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }

        return $default;
    }

    private function extractTablePrefix(string $content): ?string
    {
        // Support an explicitly empty prefix assignment: $table_prefix = '';
        if (preg_match("/\\\$table_prefix\\s*=\\s*['\"]([^'\"]*)['\"]/", $content, $matches)) {
            return (string) $matches[1];
        }

        // The prefix may live in an included file; the caller picks the fallback.
        return null;
    }
}
