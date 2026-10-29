<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Services\Backup\ChunkDomainReplacer;

final class ChunkDomainReplacerTest extends TestCase
{
    private string $sourceUrl = 'https://old.example.com';

    private string $destinationUrl = 'https://new.example.com';

    private ChunkDomainReplacer $replacer;

    protected function setUp(): void
    {
        $this->replacer = new ChunkDomainReplacer($this->sourceUrl, $this->destinationUrl);
    }

    public function testOrdinarySourceAddressIsReplaced(): void
    {
        $result = $this->replacer->replaceInData([[
            'url' => 'https://old.example.com/page',
        ]]);

        $this->assertSame([[
            'url' => 'https://new.example.com/page',
        ]], $result);
    }

    public function testHttpAndHttpsFormsAreReplaced(): void
    {
        $httpsResult = $this->replacer->replaceInData([[
            'url' => 'https://old.example.com/page',
        ]]);
        $httpResult = $this->replacer->replaceInData([[
            'url' => 'http://old.example.com/page',
        ]]);

        $this->assertSame([[
            'url' => 'https://new.example.com/page',
        ]], $httpsResult);
        $this->assertSame([[
            'url' => 'http://new.example.com/page',
        ]], $httpResult);
    }

    public function testUrlEncodedFormIsReplaced(): void
    {
        $result = $this->replacer->replaceInData([[
            'url' => 'https%3A%2F%2Fold.example.com/page',
        ]]);

        $this->assertSame([[
            'url' => 'https%3A%2F%2Fnew.example.com/page',
        ]], $result);
    }

    public function testSlashEscapedFormIsReplaced(): void
    {
        // For this address, addslashes of the source equals the plain source form.
        $result = $this->replacer->replaceInData([[
            'url' => 'https://old.example.com/page',
        ]]);

        $this->assertSame([[
            'url' => 'https://new.example.com/page',
        ]], $result);
    }

    public function testSerializedStringIsRewrittenFromInsideAndReturnedSerialized(): void
    {
        $input = 'a:1:{s:4:"link";s:28:"https://old.example.com/page";}';

        $result = $this->replacer->replaceInData([[
            'opt' => $input,
        ]]);

        $this->assertSame([[
            'opt' => 'a:1:{s:4:"link";s:28:"https://new.example.com/page";}',
        ]], $result);
    }

    public function testSerializedObjectIsNotInstantiated(): void
    {
        DomainReplacerProbe::$woke = 0;
        $input = serialize(new DomainReplacerProbe());

        $result = $this->replacer->replaceInData([[
            'opt' => $input,
        ]]);

        $this->assertSame(0, DomainReplacerProbe::$woke);
        $this->assertSame([[
            'opt' => $input,
        ]], $result);
    }

    public function testSerializedValueWithUnloadedClassIsLeftUntouched(): void
    {
        $input = 'O:27:"ActionScheduler_SimpleSchedule":0:{}';

        $result = $this->replacer->replaceInData([[
            'opt' => $input,
        ]]);

        $this->assertSame([[
            'opt' => 'O:27:"ActionScheduler_SimpleSchedule":0:{}',
        ]], $result);
    }

    public function testDataUnchangedWhenSourceOrDestinationEmptyOrAddressesAreSame(): void
    {
        $input = [['url' => 'https://old.example.com/page']];

        $emptySource = (new ChunkDomainReplacer('', $this->destinationUrl))->replaceInData($input);
        $emptyDestination = (new ChunkDomainReplacer($this->sourceUrl, ''))->replaceInData($input);
        $sameAddress = (new ChunkDomainReplacer($this->sourceUrl, $this->sourceUrl))->replaceInData($input);

        $this->assertSame($input, $emptySource);
        $this->assertSame($input, $emptyDestination);
        $this->assertSame($input, $sameAddress);
    }

    public function testNullCellStaysNull(): void
    {
        $result = $this->replacer->replaceInData([[
            'v' => null,
        ]]);

        $this->assertSame([[
            'v' => null,
        ]], $result);
    }
}

final class DomainReplacerProbe
{
    public static int $woke = 0;

    public string $url = 'https://old.example.com/page';

    public function __wakeup(): void
    {
        self::$woke++;
    }
}
