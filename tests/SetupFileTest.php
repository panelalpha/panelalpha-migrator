<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\SetupFile;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SetupFileTest extends TestCase
{
    use TemporaryDirectory;

    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $path;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/OptionStubs.php';
        $this->path = $this->createTemporaryDirectory() . SetupFile::FILE_NAME;
    }

    private function write(string $code): void
    {
        file_put_contents($this->path, '<?php ' . $code);
    }

    public function testWithoutAFileNothingHappens(): void
    {
        $this->assertFalse(SetupFile::import($this->path));
        $this->assertNull(TokenHelper::getToken());
    }

    public function testAValidFileIsImportedAndDeleted(): void
    {
        $this->write("return ['token' => '" . self::TOKEN . "', 'mode' => 'destination', 'showButtons' => false, 'allowed_destinations' => ['10.0.0.5']];");

        $this->assertTrue(SetupFile::import($this->path));

        $this->assertFileDoesNotExist($this->path);
        $this->assertSame(self::TOKEN, TokenHelper::getToken());
        $this->assertFalse($GLOBALS['wpm_option_autoload'][TokenHelper::TOKEN_OPTION]);
        $this->assertSame(
            ['mode' => 'destination', 'show_buttons' => false, 'allowed_destinations' => ['10.0.0.5']],
            TokenHelper::getSettings()
        );
    }

    public function testAnInvalidTokenIsIgnoredButTheOtherValuesAreKept(): void
    {
        TokenHelper::saveToken(self::TOKEN);
        $this->write("return ['token' => 'not-a-token', 'mode' => 'destination'];");

        $this->assertTrue(SetupFile::import($this->path));

        $this->assertFileDoesNotExist($this->path);
        $this->assertSame(self::TOKEN, TokenHelper::getToken());
        $this->assertSame('destination', TokenHelper::getMode());
    }

    public function testInvalidSettingsAreIgnored(): void
    {
        $this->write("return ['mode' => 'both', 'showButtons' => 'no', 'allowed_destinations' => 'host'];");

        $this->assertTrue(SetupFile::import($this->path));

        $this->assertArrayNotHasKey(TokenHelper::SETTINGS_OPTION, $GLOBALS['wpm_options']);
        $this->assertSame('source', TokenHelper::getMode());
    }

    public function testAFileThatCannotBeDeletedChangesNothing(): void
    {
        TokenHelper::saveToken(self::TOKEN);
        $this->write("return ['token' => '" . str_repeat('a', 64) . "', 'mode' => 'destination'];");
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root can delete files in a read-only folder.');
        }

        // In a read-only folder the delete fails.
        $directory = dirname($this->path) . '/locked/';
        mkdir($directory);
        $locked = $directory . SetupFile::FILE_NAME;
        rename($this->path, $locked);
        chmod($directory, 0555);

        try {
            $this->assertFalse(SetupFile::import($locked));
        } finally {
            chmod($directory, 0755);
        }

        $this->assertFileExists($locked);
        $this->assertSame(self::TOKEN, TokenHelper::getToken());
        $this->assertSame('source', TokenHelper::getMode());
        $this->assertArrayNotHasKey(TokenHelper::SETTINGS_OPTION, $GLOBALS['wpm_options']);
    }

    public function testAFileThatDoesNotReturnAnArrayIsOnlyDeleted(): void
    {
        $this->write("return 'nope';");

        $this->assertTrue(SetupFile::import($this->path));

        $this->assertFileDoesNotExist($this->path);
        $this->assertNull(TokenHelper::getToken());
        $this->assertSame([], $GLOBALS['wpm_options']);
    }

    public function testOnlyTheKeysInTheFileChange(): void
    {
        TokenHelper::saveToken(self::TOKEN);
        TokenHelper::saveSettings(['mode' => 'destination', 'show_buttons' => false, 'allowed_destinations' => ['a.example.com']]);
        $this->write("return ['showButtons' => true];");

        $this->assertTrue(SetupFile::import($this->path));

        $this->assertSame(self::TOKEN, TokenHelper::getToken());
        $this->assertSame(
            ['mode' => 'destination', 'show_buttons' => true, 'allowed_destinations' => ['a.example.com']],
            TokenHelper::getSettings()
        );
    }
}
