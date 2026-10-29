<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\BinarySafeEncoder;

final class BinarySafeEncoderTest extends TestCase
{
    public function testValidUtf8RowsAreLeftUnchanged(): void
    {
        $rows = [[1, 'Zażółć gęślą jaźń', null, 3.5]];

        $result = BinarySafeEncoder::encode($rows);

        $this->assertSame($rows, $result['data']);
        $this->assertSame([], $result['binary_map']);
    }

    public function testInvalidUtf8ValueIsBase64EncodedAndMapped(): void
    {
        $binary = "\xff\xfe\x00\x01";

        $result = BinarySafeEncoder::encode([
            ['text', 'text'],
            ['text', $binary],
        ]);

        $this->assertSame(base64_encode($binary), $result['data'][1][1]);
        $this->assertSame([[1, 1]], $result['binary_map']);
    }

    public function testEncodedRowsDecodeToTheOriginal(): void
    {
        $rows = [
            [1, "\x80abc", 'plain'],
            [2, 'plain', "\xc3\x28"],
        ];

        $encoded = BinarySafeEncoder::encode($rows);
        $decoded = BinarySafeEncoder::decode($encoded['data'], $encoded['binary_map']);

        $this->assertSame($rows, $decoded);
    }

    public function testEncodedRowsSurviveJsonEncoding(): void
    {
        $rows = [[1, "\xff\xd8\xff\xe0"]];

        $encoded = BinarySafeEncoder::encode($rows);
        $json = json_encode($encoded['data']);

        $this->assertNotFalse($json);
        $this->assertSame($rows, BinarySafeEncoder::decode(json_decode($json, true), $encoded['binary_map']));
    }

    public function testDecodeIgnoresPositionsThatDoNotExist(): void
    {
        $rows = [['a', 'b']];

        $this->assertSame($rows, BinarySafeEncoder::decode($rows, [[5, 0], [0, 9]]));
    }

    public function testDecodeLeavesValueThatIsNotBase64Unchanged(): void
    {
        $rows = [['not base64 !!']];

        $this->assertSame($rows, BinarySafeEncoder::decode($rows, [[0, 0]]));
    }
}
