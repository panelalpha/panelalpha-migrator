<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Services\Backup\ChunkHttpSender;
use WordpressMigrator\Tests\Support\FakeDestination;

final class ChunkHttpSenderTest extends TestCase
{
    private FakeDestination $destination;

    private array $chunk = [
        'type' => 'file_chunk',
        'migration_id' => 'mig_1',
        'content' => 'aGVsbG8=',
    ];

    protected function setUp(): void
    {
        $this->destination = FakeDestination::get();
    }

    public function testChunkIsPostedAsCompressedJsonToRestorePhp(): void
    {
        $this->destination->respondWith('restore.php', 200, '{"success":true,"processed":true}');
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');

        $result = $sender->send($this->chunk);

        $this->assertSame(['success' => true, 'processed' => true], $result);
        $request = $this->destination->requests()[0];
        $this->assertSame('/wp-content/plugins/wordpress-migrator/restore.php?action=collect-and-restore', $request['uri']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('secret-token', $request['headers']['x-migrator-token']);
        $this->assertArrayNotHasKey('x-migrator-encoding', $request['headers']);
        $this->assertSame(JsonCompressor::compressToJson($this->chunk), $request['body']);
    }

    public function testDestinationWithTrailingSlashGetsOneSlash(): void
    {
        $sender = new ChunkHttpSender($this->destination->url() . '/', 'secret-token');

        $sender->send($this->chunk);

        $this->assertStringStartsWith('/wp-content/', $this->destination->requests()[0]['uri']);
    }

    public function testBase64ModeEncodesTheBody(): void
    {
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token', null, true);

        $sender->send($this->chunk);

        $request = $this->destination->requests()[0];
        $this->assertSame('application/octet-stream', $request['headers']['content-type']);
        $this->assertSame('base64', $request['headers']['x-migrator-encoding']);
        $this->assertSame(JsonCompressor::compressToJson($this->chunk), base64_decode($request['body'], true));
    }

    public function testErrorStatusFromTheDestinationThrows(): void
    {
        $this->destination->respondWith('restore.php', 403, '{"error":"Unauthorized - invalid token"}');
        $sender = new ChunkHttpSender($this->destination->url(), 'wrong');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination returned error: 403 - {"error":"Unauthorized - invalid token"}');

        $sender->send($this->chunk);
    }

    public function testConnectionFailureThrows(): void
    {
        $sender = new ChunkHttpSender(FakeDestination::closedPortUrl(), 'secret-token', '127.0.0.1');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to send chunk: ');

        $sender->send($this->chunk);
    }
}
