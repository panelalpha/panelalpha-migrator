<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\JsonCompressor;

final class JsonCompressorTest extends TestCase
{
    public function testKnownKeysAreShortenedAtEveryLevel(): void
    {
        $result = JsonCompressor::compress([
            'migration_id' => 'mig_1',
            'source_table_prefix' => 'wp_',
            'file' => [
                'path' => 'wp-content/a.jpg',
                'chunk_info' => ['chunk_number' => 1, 'total_chunks' => 2],
            ],
            'binary_map' => [[0, 1]],
        ]);

        $this->assertSame([
            'm' => 'mig_1',
            'stp' => 'wp_',
            'f' => [
                'p' => 'wp-content/a.jpg',
                'ci' => ['n' => 1, 'tc' => 2],
            ],
            'bm' => [[0, 1]],
        ], $result);
    }

    public function testUnknownKeysAndValuesAreLeftAlone(): void
    {
        $data = ['custom_key' => 'path', 'list' => ['type', 'table']];

        $this->assertSame($data, JsonCompressor::compress($data));
    }

    public function testCompressedDataDecompressesToTheOriginal(): void
    {
        $data = [
            'chunk_id' => 'c1',
            'type' => 'table_data',
            'table' => ['name' => 'wp_posts', 'columns' => ['ID', 'post_title']],
            'data' => [[1, 'Hello']],
            'checksum' => 'abc',
        ];

        $this->assertSame($data, JsonCompressor::decompress(JsonCompressor::compress($data)));
    }

    public function testCamelCaseAliasDecompressesToItsSnakeCaseForm(): void
    {
        $result = JsonCompressor::decompress(JsonCompressor::compress([
            'siteUrl' => 'https://old.example.com',
            'destinationUrl' => 'https://new.example.com',
        ]));

        $this->assertSame([
            'site_url' => 'https://old.example.com',
            'destination_url' => 'https://new.example.com',
        ], $result);
    }

    public function testShortTopLevelKeyMarksDataAsCompressed(): void
    {
        $this->assertTrue(JsonCompressor::isCompressed(['m' => 'mig_1']));
        $this->assertFalse(JsonCompressor::isCompressed(['migration_id' => 'mig_1']));
        $this->assertFalse(JsonCompressor::isCompressed([]));
    }

    public function testJsonRoundTripRestoresTheLongKeys(): void
    {
        $data = ['migration_id' => 'mig_1', 'status' => 'pending'];

        $json = JsonCompressor::compressToJson($data);

        $this->assertSame('{"m":"mig_1","st":"pending"}', $json);
        $this->assertSame($data, JsonCompressor::decompressFromJson($json));
    }

    public function testUncompressedJsonIsReturnedAsIs(): void
    {
        $this->assertSame(
            ['migration_id' => 'mig_1'],
            JsonCompressor::decompressFromJson('{"migration_id":"mig_1"}')
        );
    }

    public function testJsonThatIsNotAnObjectGivesAnEmptyArray(): void
    {
        $this->assertSame([], JsonCompressor::decompressFromJson('not json'));
        $this->assertSame([], JsonCompressor::decompressFromJson('"text"'));
    }
}
