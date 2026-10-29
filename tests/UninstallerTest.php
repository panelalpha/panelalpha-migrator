<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Tests\Support\RecordingWpdb;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;
use PanelAlpha\Migrator\Uninstaller;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UninstallerTest extends TestCase
{
    use TemporaryDirectory;

    private object $wpdb;

    private string $contentDir;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressFunctions.php';

        eval('namespace { $GLOBALS["wpm_options"] = []; $GLOBALS["wpm_cleared"] = [];
            function delete_option($name) { unset($GLOBALS["wpm_options"][$name]); return true; }
            function wp_clear_scheduled_hook($hook) { $GLOBALS["wpm_cleared"][] = $hook; return 0; } }');

        // RecordingWpdb has no options table, so the secret option names are answered here.
        $this->wpdb = new class extends \stdClass {
            public string $options = 'wp_options';
            public RecordingWpdb $inner;

            public function __construct()
            {
                $this->inner = new RecordingWpdb();
            }

            public function get_col(string $query): array
            {
                if (str_starts_with($query, 'SELECT option_name FROM `wp_options`')) {
                    $this->inner->queries[] = $query;

                    return array_values(array_filter(array_keys($GLOBALS['wpm_options']), static function ($name): bool {
                        return str_starts_with($name, 'panelalpha_migrator_secret_');
                    }));
                }

                return $this->inner->get_col($query);
            }

            public function __call(string $name, array $arguments)
            {
                return $this->inner->$name(...$arguments);
            }
        };
        $this->contentDir = rtrim($this->createTemporaryDirectory(), '/');
    }

    private function uninstall(): void
    {
        (new Uninstaller($this->wpdb, $this->contentDir))->run();
    }

    public function testOptionsAreRemovedAndOthersKept(): void
    {
        $GLOBALS['wpm_options'] = [
            'panelalpha_migrator_token' => 'a',
            'panelalpha_migrator_settings' => ['x' => 1],
            'panelalpha_migrator_secret_mig_1' => ['token' => 's'],
            'panelalpha_migrator_secret_mig_2' => ['token' => 't'],
            'blogname' => 'Site',
            'panelalpha_other' => 'keep',
        ];

        $this->uninstall();

        $this->assertSame(['blogname', 'panelalpha_other'], array_keys($GLOBALS['wpm_options']));
        $this->assertSame(['panelalpha_migrator_cleanup'], $GLOBALS['wpm_cleared']);
    }

    public function testOnlyMigrationTablesAreDropped(): void
    {
        foreach (['pmt_0123ab_wp_options', 'pmb_0123ab_wp_posts', 'pmt_0123ab_' . sha1('x'), 'pmt_notahash_x', 'pmb_0123AB_x', 'pmt_0123ab', 'wp_posts', 'wp_pmt_0123ab_x'] as $table) {
            $this->wpdb->inner->sqlite('CREATE TABLE `' . $table . '` (id integer)');
        }

        $this->uninstall();

        $this->assertSame(['pmb_0123AB_x', 'pmt_0123ab', 'pmt_notahash_x', 'wp_pmt_0123ab_x', 'wp_posts'], $this->wpdb->inner->tables());
        $this->assertContains('SET SESSION foreign_key_checks = 0', $this->wpdb->inner->queries);
        $this->assertSame('SET SESSION foreign_key_checks = 1', end($this->wpdb->inner->queries));
    }

    public function testNoTablesMeansNoDropAndNoForeignKeyChange(): void
    {
        $this->uninstall();

        foreach ($this->wpdb->inner->queries as $query) {
            $this->assertStringNotContainsString('DROP TABLE', $query);
            $this->assertStringNotContainsString('foreign_key_checks', $query);
        }
    }

    public function testPluginFilesAreRemovedAndForeignFilesKept(): void
    {
        $dir = $this->contentDir . '/migrations';
        $id = 'mig_20260101_000000_0123456789abcdef0123456789abcdef';
        self::writeFile($dir . '/.htaccess', 'deny');
        self::writeFile($dir . '/index.php', '<?php');
        self::writeFile($dir . '/collected/' . $id . '/status.json', '{}');
        self::writeFile($dir . '/' . $id . '.json', '{}');
        self::writeFile($dir . '/' . $id . '.secret.php', '<?php exit;');
        self::writeFile($dir . '/skipped_files_' . $id . '.log', 'x');
        self::writeFile($dir . '/' . $id . '/staged/a.txt', 'x');
        self::writeFile($dir . '/readme.txt', 'not ours');
        self::writeFile($dir . '/my-folder/file.txt', 'not ours');
        self::writeFile($dir . '/cache/file.txt', 'not ours');
        self::writeFile($dir . '/cache.json', 'not ours');
        self::writeFile($this->contentDir . '/panelalpha-migrator-lock.php', 'x');
        self::writeFile($this->contentDir . '/panelalpha-migrator-lock.json', 'x');
        self::writeFile($this->contentDir . '/panelalpha-migrator-setup.php', 'x');
        self::writeFile($this->contentDir . '/other.php', 'keep');

        $this->uninstall();

        $this->assertSame(['cache', 'cache.json', 'my-folder', 'readme.txt'], array_values(array_diff(scandir($dir), ['.', '..'])));
        $this->assertSame(['migrations', 'other.php'], array_values(array_diff(scandir($this->contentDir), ['.', '..'])));
    }

    public function testAnEmptyMigrationsFolderIsRemoved(): void
    {
        self::writeFile($this->contentDir . '/migrations/.htaccess', 'deny');
        self::writeFile($this->contentDir . '/migrations/index.php', '<?php');
        mkdir($this->contentDir . '/migrations/collected');

        $this->uninstall();

        $this->assertDirectoryDoesNotExist($this->contentDir . '/migrations');
    }

    public function testALinkIsRemovedWithoutFollowingIt(): void
    {
        $target = $this->createTemporaryDirectory();
        self::writeFile($target . 'keep.txt', 'x');
        mkdir($this->contentDir . '/migrations');
        symlink(rtrim($target, '/'), $this->contentDir . '/migrations/collected');

        $this->uninstall();

        $this->assertFileExists($target . 'keep.txt');
        $this->assertDirectoryDoesNotExist($this->contentDir . '/migrations');
    }

    public function testItWorksWhenNothingExists(): void
    {
        $this->uninstall();

        $this->assertSame([], $GLOBALS['wpm_options']);
        $this->assertSame([], array_diff(scandir($this->contentDir), ['.', '..']));
    }
}
