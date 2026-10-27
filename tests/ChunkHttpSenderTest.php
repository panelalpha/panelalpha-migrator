<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Services\Backup\ChunkHttpSender;
use WordpressMigrator\Tests\Support\CurlOptionCapture;
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
        $this->destination->respondWith('restore.php', 200, '{"success":true,"processed":true,"migration_id":"mig_1"}');
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');
        CurlOptionCapture::reset();

        $result = $sender->send($this->chunk);

        $this->assertSame(['success' => true, 'processed' => true, 'migration_id' => 'mig_1'], $result);
        $request = $this->destination->requests()[0];
        $this->assertSame('/wp-content/plugins/wordpress-migrator/restore.php?action=collect-and-restore', $request['uri']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('secret-token', $request['headers']['x-migrator-token']);
        $this->assertArrayNotHasKey('x-migrator-encoding', $request['headers']);
        $this->assertSame(JsonCompressor::compressToJson($this->chunk), $request['body']);
        $this->assertTrue(CurlOptionCapture::peerVerificationEnabled());
        $this->assertTrue(CurlOptionCapture::hostVerificationEnabled());
    }

    public function testTlsArgsTurnOffVerificationForBothRequestModes(): void
    {
        foreach ([false, true] as $base64) {
            $sender = new ChunkHttpSender($this->destination->url(), 'secret-token', null, $base64, ['sslverify' => false]);
            CurlOptionCapture::reset();

            $sender->send($this->chunk);

            $this->assertFalse(CurlOptionCapture::peerVerificationEnabled());
            $this->assertFalse(CurlOptionCapture::hostVerificationEnabled());
        }
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
        CurlOptionCapture::reset();

        $sender->send($this->chunk);

        $request = $this->destination->requests()[0];
        $this->assertSame('application/octet-stream', $request['headers']['content-type']);
        $this->assertSame('base64', $request['headers']['x-migrator-encoding']);
        $this->assertSame(JsonCompressor::compressToJson($this->chunk), base64_decode($request['body'], true));
        $this->assertTrue(CurlOptionCapture::peerVerificationEnabled());
        $this->assertTrue(CurlOptionCapture::hostVerificationEnabled());
    }

    public function testPinnedIpIsSentAsCurlResolveWhenTheHostIsNotReachableByName(): void
    {
        $port = $this->destination->port();
        $sender = new ChunkHttpSender('http://destination.invalid:' . $port, 'secret-token');
        $sender->setIp('127.0.0.1');
        CurlOptionCapture::reset();

        $sender->send($this->chunk);

        $this->assertSame(['destination.invalid:' . $port . ':127.0.0.1'], CurlOptionCapture::get(CURLOPT_RESOLVE));
        $this->assertCount(1, $this->destination->requests());
    }

    public function testNoIpMeansNoCurlResolve(): void
    {
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');
        CurlOptionCapture::reset();

        $sender->send($this->chunk);

        $this->assertNull(CurlOptionCapture::get(CURLOPT_RESOLVE));
    }

    public function testErrorStatusFromTheDestinationThrows(): void
    {
        $this->destination->respondWith('restore.php', 403, '{"error":"Unauthorized - invalid token"}');
        $sender = new ChunkHttpSender($this->destination->url(), 'wrong');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination returned error: 403 - {"error":"Unauthorized - invalid token"}');

        $sender->send($this->chunk);
    }

    public function testLongErrorBodyIsCutInTheMessage(): void
    {
        $this->destination->respondWith('restore.php', 500, str_repeat('y', 1000));
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination returned error: 500 - ' . str_repeat('y', 300));

        try {
            $sender->send($this->chunk);
        } catch (Exception $exception) {
            $this->assertSame(300, strlen(substr($exception->getMessage(), strlen('Destination returned error: 500 - '))));
            throw $exception;
        }
    }

    public function testConnectionFailureThrows(): void
    {
        $sender = new ChunkHttpSender(FakeDestination::closedPortUrl(), 'secret-token', '127.0.0.1');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to send chunk: ');

        $sender->send($this->chunk);
    }

    public function testAcknowledgementMustMatchTheMigrationAndChunk(): void
    {
        $this->chunk['chunk_id'] = 'file_chunk_00001';
        $this->destination->respondWith('restore.php', 200, json_encode([
            'success' => true,
            'migration_id' => 'mig_other',
            'chunk_id' => 'file_chunk_00001',
        ]));
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination acknowledgement migration id does not match');

        $sender->send($this->chunk);
    }

    public function testAcknowledgementRejectsAChunkIdThatDoesNotMatch(): void
    {
        $this->chunk['chunk_id'] = 'file_chunk_00001';
        $this->destination->respondWith('restore.php', 200, json_encode([
            'success' => true,
            'migration_id' => 'mig_1',
            'chunk_id' => 'file_chunk_00002',
        ]));
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination acknowledgement chunk id does not match');

        $sender->send($this->chunk);
    }

    public function testRetryableOrFailedAcknowledgementIsNotDelivered(): void
    {
        $this->chunk['chunk_id'] = 'file_chunk_00001';
        $this->destination->respondWith('restore.php', 200, json_encode([
            'success' => true,
            'migration_id' => 'mig_1',
            'chunk_id' => 'file_chunk_00001',
            'result' => ['failed' => true, 'can_retry' => true, 'reason' => 'disk full'],
        ]));
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination did not deliver the chunk: disk full');

        $sender->send($this->chunk);
    }

    public function testExplicitSkipWithAReasonIsAcknowledged(): void
    {
        $this->chunk['chunk_id'] = 'file_chunk_00001';
        $this->destination->respondWith('restore.php', 200, json_encode([
            'success' => true,
            'migration_id' => 'mig_1',
            'chunk_id' => 'file_chunk_00001',
            'result' => [
                'skipped' => true,
                'can_retry' => false,
                'reason' => 'Critical file excluded from restore',
            ],
        ]));
        $sender = new ChunkHttpSender($this->destination->url(), 'secret-token');

        $result = $sender->send($this->chunk);

        $this->assertTrue($result['result']['skipped']);
        $this->assertSame('Critical file excluded from restore', $result['result']['reason']);
    }
}
