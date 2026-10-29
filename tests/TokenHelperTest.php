<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\TokenHelper;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TokenHelperTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/OptionStubs.php';
    }

    public function testNoTokenIsNull(): void
    {
        $this->assertNull(TokenHelper::getToken());

        update_option(TokenHelper::TOKEN_OPTION, '', false);
        $this->assertNull(TokenHelper::getToken());

        update_option(TokenHelper::TOKEN_OPTION, ['x'], false);
        $this->assertNull(TokenHelper::getToken());
    }

    public function testSaveTokenStoresItWithoutAutoload(): void
    {
        $this->assertTrue(TokenHelper::saveToken('abc'));

        $this->assertSame('abc', TokenHelper::getToken());
        $this->assertFalse($GLOBALS['wpm_option_autoload'][TokenHelper::TOKEN_OPTION]);
    }

    public function testSaveTokenFailsWhenTheValueCannotBeReadBack(): void
    {
        $GLOBALS['wpm_options_read_only'] = true;

        $this->assertFalse(TokenHelper::saveToken('abc'));
        $this->assertNull(TokenHelper::getToken());
    }

    public function testSettingsDefaultsWithoutAnOption(): void
    {
        $this->assertSame(
            ['mode' => 'source', 'show_buttons' => true, 'allowed_destinations' => []],
            TokenHelper::getSettings()
        );
        $this->assertSame('source', TokenHelper::getMode());
        $this->assertTrue(TokenHelper::getShowButtons());
        $this->assertSame([], TokenHelper::getAllowedDestinations());
    }

    public function testStoredValuesAreMergedWithTheDefaults(): void
    {
        update_option(TokenHelper::SETTINGS_OPTION, ['mode' => 'destination']);

        $this->assertSame(
            ['mode' => 'destination', 'show_buttons' => true, 'allowed_destinations' => []],
            TokenHelper::getSettings()
        );
    }

    public function testValuesOfTheWrongTypeAndUnknownKeysAreDropped(): void
    {
        update_option(TokenHelper::SETTINGS_OPTION, [
            'mode' => 'both',
            'show_buttons' => 'yes',
            'allowed_destinations' => ['a.example.com', 5, ['x'], 'b.example.com'],
            'unknown' => 'value',
        ]);

        $this->assertSame(
            ['mode' => 'source', 'show_buttons' => true, 'allowed_destinations' => ['a.example.com', 'b.example.com']],
            TokenHelper::getSettings()
        );
    }

    public function testAnOptionThatIsNotAnArrayGivesTheDefaults(): void
    {
        update_option(TokenHelper::SETTINGS_OPTION, 'nope');

        $this->assertSame('source', TokenHelper::getMode());
        $this->assertTrue(TokenHelper::getShowButtons());
    }

    public function testSaveSettingsStoresTheCleanedValues(): void
    {
        $this->assertTrue(TokenHelper::saveSettings([
            'mode' => 'destination',
            'show_buttons' => false,
            'allowed_destinations' => ['10.0.0.5'],
            'unknown' => 1,
        ]));

        $this->assertSame(
            ['mode' => 'destination', 'show_buttons' => false, 'allowed_destinations' => ['10.0.0.5']],
            $GLOBALS['wpm_options'][TokenHelper::SETTINGS_OPTION]
        );
        $this->assertSame('destination', TokenHelper::getMode());
        $this->assertFalse(TokenHelper::getShowButtons());
        $this->assertSame(['10.0.0.5'], TokenHelper::getAllowedDestinations());
    }

    public function testSaveSettingsFailsWhenTheOptionCannotBeWritten(): void
    {
        $GLOBALS['wpm_options_read_only'] = true;

        $this->assertFalse(TokenHelper::saveSettings(['mode' => 'destination']));
        $this->assertSame('source', TokenHelper::getMode());
    }

    public function testMigrationTokenIsTakenFromTheMigrationData(): void
    {
        $this->assertSame('long', TokenHelper::forMigration(['token' => 'long']));

        $this->expectException(\RuntimeException::class);
        TokenHelper::forMigration([]);
    }
}
