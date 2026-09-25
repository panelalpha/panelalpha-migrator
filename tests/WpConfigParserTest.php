<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Restore\WpConfigParser;

final class WpConfigParserTest extends TestCase
{
    private string $abspath;

    protected function setUp(): void
    {
        $this->abspath = sys_get_temp_dir() . '/wp-migrator-config-test-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->abspath);
    }

    protected function tearDown(): void
    {
        @unlink($this->abspath . 'wp-config.php');
        @rmdir($this->abspath);
    }

    public function testDatabaseLoginIsReadFromWpConfig(): void
    {
        $this->writeWpConfig(<<<'PHP'
            define( 'DB_NAME', 'site_db' );
            define( 'DB_USER', 'site_user' );
            define( 'DB_PASSWORD', 's3cret' );
            define( 'DB_HOST', 'db.internal' );
            $table_prefix = 'wpx_';
            PHP);

        $this->assertSame([
            'db_name' => 'site_db',
            'db_user' => 'site_user',
            'db_password' => 's3cret',
            'db_host' => 'db.internal',
            'table_prefix' => 'wpx_',
        ], $this->parse());
    }

    public function testDoubleQuotesAndNoSpacesAreAccepted(): void
    {
        $this->writeWpConfig(<<<'PHP'
            define("DB_NAME","site_db");
            define("DB_USER","site_user");
            define("DB_HOST","db.internal");
            $table_prefix="wp_";
            PHP);

        $config = $this->parse();

        $this->assertSame('site_db', $config['db_name']);
        $this->assertSame('site_user', $config['db_user']);
        $this->assertSame('', $config['db_password']);
        $this->assertSame('wp_', $config['table_prefix']);
    }

    public function testHostWithPortOrSocketIsKept(): void
    {
        $this->writeWpConfig(<<<'PHP'
            define('DB_NAME', 'site_db');
            define('DB_USER', 'site_user');
            define('DB_HOST', 'localhost:/var/run/mysqld/mysqld.sock');
            PHP);

        $this->assertSame('localhost:/var/run/mysqld/mysqld.sock', $this->parse()['db_host']);
    }

    public function testLocalhostBecomesLoopbackAddress(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('localhost is kept on Windows');
        }

        $this->writeWpConfig(<<<'PHP'
            define('DB_NAME', 'site_db');
            define('DB_USER', 'site_user');
            define('DB_HOST', 'localhost');
            PHP);

        $this->assertSame('127.0.0.1', $this->parse()['db_host']);
    }

    public function testMissingHostDefaultsToLocalhost(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('localhost is kept on Windows');
        }

        $this->writeWpConfig(<<<'PHP'
            define('DB_NAME', 'site_db');
            define('DB_USER', 'site_user');
            PHP);

        $this->assertSame('127.0.0.1', $this->parse()['db_host']);
    }

    public function testExplicitlyEmptyTablePrefixIsKept(): void
    {
        $this->writeWpConfig(<<<'PHP'
            define('DB_NAME', 'site_db');
            define('DB_USER', 'site_user');
            define('DB_HOST', 'db.internal');
            $table_prefix = '';
            PHP);

        $this->assertSame('', $this->parse()['table_prefix']);
    }

    public function testMissingTablePrefixIsEmpty(): void
    {
        $this->writeWpConfig(<<<'PHP'
            define('DB_NAME', 'site_db');
            define('DB_USER', 'site_user');
            define('DB_HOST', 'db.internal');
            PHP);

        $this->assertSame('', $this->parse()['table_prefix']);
    }

    private function writeWpConfig(string $body): void
    {
        file_put_contents($this->abspath . 'wp-config.php', "<?php\n" . $body . "\n");
    }

    private function parse(): array
    {
        return (new WpConfigParser($this->abspath))->parse();
    }
}
