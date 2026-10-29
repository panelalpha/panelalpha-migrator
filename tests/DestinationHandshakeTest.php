<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\DestinationNotAllowedException;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Helpers\MigrationLock;
use PanelAlpha\Migrator\Services\Migration\DestinationHandshake;
use PanelAlpha\Migrator\Services\Migration\DestinationHandshakeException;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Tests\Support\CurlOptionCapture;
use PanelAlpha\Migrator\Tests\Support\FakeDestination;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class DestinationHandshakeTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private const ID = 'mig_20260101_000000_0123456789abcdef0123456789abcdef';

    private FakeDestination $destination;

    private MigrationStorageService $storage;

    private MigrationLock $lock;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        Config::set('allowed_destinations', ['127.0.0.1']);
        $this->destination = FakeDestination::get();
        $this->storage = new MigrationStorageService();
        $this->lock = new MigrationLock($this->createTemporaryDirectory() . 'lock.json');
    }

    private function handshake(): DestinationHandshake
    {
        return new DestinationHandshake(null, $this->storage, $this->lock);
    }

    private function target(array $overrides = []): array
    {
        return $overrides + ['destinationUrl' => $this->destination->url(), 'token' => 'destination-token'];
    }

    private function assertCheckFails(array $target, string $code, string $message): void
    {
        try {
            $this->handshake()->check($target);
            $this->fail('Expected the check to fail');
        } catch (DestinationHandshakeException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function testPrettyLinksAreUsedWhenTheDestinationAnswers(): void
    {
        $this->assertSame('pretty', $this->handshake()->check($this->target()));

        $requests = $this->destination->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertMatchesRegularExpression('#^/wp-json/panelalpha-migrator/v1/receive/check\?_=\d+$#', $requests[0]['uri']);
        $this->assertSame('destination-token', $requests[0]['headers']['x-migrator-token']);
        $this->assertSame('', $requests[0]['body']);
    }

    public function testNoQueryTokenIsSentToTheDestination(): void
    {
        $this->handshake()->check($this->target());

        $this->assertStringNotContainsString('token', $this->destination->requests()[0]['uri']);
    }

    public function test404OnThePrettyRouteFallsBackToTheRestRouteQuery(): void
    {
        $this->destination->respondWith('/wp-json/', 404, '{"code":"rest_no_route"}');

        $this->assertSame('plain', $this->handshake()->check($this->target()));

        $requests = $this->destination->requests();
        $this->assertCount(2, $requests);
        $this->assertMatchesRegularExpression('#^/\?rest_route=/panelalpha-migrator/v1/receive/check&_=\d+$#', $requests[1]['uri']);
        $this->assertSame('destination-token', $requests[1]['headers']['x-migrator-token']);
    }

    public function testHomePageOnThePrettyRouteFallsBackToTheRestRouteQuery(): void
    {
        $this->destination->respondWith('/wp-json/', 200, '<html>home page</html>');

        $this->assertSame('plain', $this->handshake()->check($this->target()));

        $this->assertCount(2, $this->destination->requests());
    }

    public function testDestinationWithoutTheReceiveRoutesIsIncompatible(): void
    {
        $this->destination->respondWith('receive/check', 404, '{"code":"rest_no_route","message":"No route was found"}');

        $this->assertCheckFails(
            $this->target(),
            'destination_incompatible',
            'Destination does not have PanelAlpha Migrator 1.2.1 or newer active. Install or update it on the destination.'
        );
        $this->assertCount(2, $this->destination->requests());
    }

    public function testOldPluginBehindNonPrettyLinksIsIncompatible(): void
    {
        $this->destination->respondWith('/wp-json/', 404, '<html>not found</html>');
        $this->destination->respondWith('rest_route=', 404, '{"code":"rest_no_route"}');

        $this->assertCheckFails(
            $this->target(),
            'destination_incompatible',
            'Destination does not have PanelAlpha Migrator 1.2.1 or newer active. Install or update it on the destination.'
        );
    }

    public function testRestApiThatNeverAnswersWithJsonIsNotReady(): void
    {
        $this->destination->respondWith('receive/check', 404, '<html>not found</html>');

        $this->assertCheckFails(
            $this->target(),
            'destination_not_ready',
            'The WordPress REST API does not answer on the destination. Check that the destination URL is the site address and that its REST API works.'
        );
    }

    public function testOlderReceiveVersionIsIncompatible(): void
    {
        $this->destination->respondWith('receive/check', 200, '{"success":true,"version":"1.2.1","receive":1}');

        $this->assertCheckFails(
            $this->target(),
            'destination_incompatible',
            'Destination does not have PanelAlpha Migrator 1.2.1 or newer active. Install or update it on the destination.'
        );
        $this->assertCount(1, $this->destination->requests());
    }

    public function testTokenRefusedByThePluginIsNotReady(): void
    {
        $this->destination->respondWith('receive/check', 401, '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that."}');

        $this->assertCheckFails(
            $this->target(),
            'destination_not_ready',
            'Destination did not accept the token. Copy the current URL with the token from the destination.'
        );
        $this->assertCount(1, $this->destination->requests());
    }

    public function testDestinationWithoutAConfiguredTokenSaysSo(): void
    {
        $this->destination->respondWith('receive/check', 401, '{"code":"rest_forbidden","message":"Destination token is not configured"}');

        $this->assertCheckFails(
            $this->target(),
            'destination_not_ready',
            'Destination token is not configured. Open PanelAlpha Migrator on the destination to create it.'
        );
    }

    public function testBlockedRestApiNamesTheStatusAndCode(): void
    {
        $this->destination->respondWith('receive/check', 403, '{"code":"rest_cannot_access","message":"Blocked"}');

        $this->assertCheckFails(
            $this->target(),
            'destination_incompatible',
            'Destination REST API is blocked (HTTP 403 rest_cannot_access). Allow /panelalpha-migrator/v1/ on the destination.'
        );
        $this->assertCount(1, $this->destination->requests());
    }

    public function testBlockedRestApiWithoutJsonNamesTheStatus(): void
    {
        $this->destination->respondWith('receive/check', 403, '<html>Forbidden</html>');

        $this->assertCheckFails(
            $this->target(),
            'destination_incompatible',
            'Destination REST API is blocked (HTTP 403). Allow /panelalpha-migrator/v1/ on the destination.'
        );
    }

    public function testServerErrorIsReportedWithTheStatusAndTheStartOfTheBody(): void
    {
        $this->destination->respondWith('receive/check', 500, str_repeat('y', 1000));

        $this->assertCheckFails(
            $this->target(),
            'destination_not_ready',
            'Destination returned error: 500 - ' . str_repeat('y', 300)
        );
    }

    public function testConnectionFailureIsNotReady(): void
    {
        try {
            $this->handshake()->check($this->target(['destinationUrl' => FakeDestination::closedPortUrl(), 'pinned_ip' => '127.0.0.1']));
            $this->fail('Expected the check to fail');
        } catch (DestinationHandshakeException $exception) {
            $this->assertSame('destination_not_ready', $exception->getErrorCode());
            $this->assertStringStartsWith('Failed to reach the destination: ', $exception->getMessage());
        }
    }

    public function testMissingTokenIsRefusedBeforeAnyRequest(): void
    {
        $this->assertCheckFails(
            ['destinationUrl' => $this->destination->url()],
            'destination_not_ready',
            'Destination token is missing. Use the full URL with the token from the destination.'
        );
        $this->assertSame([], $this->destination->requests());
    }

    public function testMissingDestinationUrlIsRefusedBeforeAnyRequest(): void
    {
        $this->assertCheckFails(['token' => 'destination-token'], 'destination_not_ready', 'Destination URL is not defined.');
        $this->assertSame([], $this->destination->requests());
    }

    public function testBlockedDestinationIsRefusedBeforeAnyRequest(): void
    {
        Config::set('allowed_destinations', []);

        $this->expectException(DestinationNotAllowedException::class);
        try {
            $this->handshake()->check($this->target());
        } finally {
            $this->assertSame([], $this->destination->requests());
        }
    }

    public function testCheckConnectsToThePinnedIp(): void
    {
        Config::set('allowed_destinations', []);
        CurlOptionCapture::reset();

        $this->handshake()->check($this->target([
            'destinationUrl' => 'http://destination.invalid:' . $this->destination->port(),
            'pinned_ip' => '127.0.0.1',
        ]));

        $this->assertSame(
            ['destination.invalid:' . $this->destination->port() . ':127.0.0.1'],
            CurlOptionCapture::get(CURLOPT_RESOLVE)
        );
    }

    public function testCertificateChecksAreOffOnlyForAManualIpWithInsecureTls(): void
    {
        CurlOptionCapture::reset();
        $this->handshake()->check($this->target(['ip' => '127.0.0.1', 'pinned_ip' => '127.0.0.1', 'insecure_tls' => true]));
        $this->assertFalse(CurlOptionCapture::peerVerificationEnabled());

        CurlOptionCapture::reset();
        $this->handshake()->check($this->target(['insecure_tls' => true]));
        $this->assertTrue(CurlOptionCapture::peerVerificationEnabled());
        $this->assertTrue(CurlOptionCapture::hostVerificationEnabled());
    }

    private function saveMigration(array $overrides = []): void
    {
        $this->storage->saveMigrationData(self::ID, $overrides + [
            'type' => 'info',
            'migrationId' => self::ID,
            'destinationUrl' => $this->destination->url(),
            'siteUrl' => 'https://old.example.test',
            'token' => 'dest-token',
            'sourceToken' => 'source-token',
            'files' => [['path' => 'a.txt', 'size' => 1, 'totalChunks' => 1, 'status' => 'pending', 'chunks' => []]],
            'tables' => [],
        ]);
    }

    public function testInfoChunkGoesToTheReceiveRouteOnceWithoutSecrets(): void
    {
        $this->saveMigration();
        $this->lock->acquire(self::ID);
        $handshake = $this->handshake();

        $handshake->sendInfo(self::ID);
        $handshake->sendInfo(self::ID);

        $requests = $this->destination->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/wp-json/panelalpha-migrator/v1/receive', $requests[0]['uri']);
        $this->assertSame('dest-token', $requests[0]['headers']['x-migrator-token']);
        $info = JsonCompressor::decompressFromJson($requests[0]['body']);
        $this->assertSame('info', $info['type']);
        $this->assertSame(self::ID, $info['migration_id']);
        $this->assertStringNotContainsString('source-token', $requests[0]['body']);
        $this->assertTrue($this->storage->loadMigrationData(self::ID)['info_sent']);
    }

    public function testInfoChunkFollowsTheSavedRestMode(): void
    {
        $this->saveMigration(['drm' => 'plain']);
        $this->lock->acquire(self::ID);

        $this->handshake()->sendInfo(self::ID);

        $this->assertSame('/?rest_route=/panelalpha-migrator/v1/receive', $this->destination->requests()[0]['uri']);
    }

    public function testInfoChunkNotConfirmedByTheDestinationIsNotRecordedAsSent(): void
    {
        $this->saveMigration();
        $this->lock->acquire(self::ID);
        $this->destination->respondWith('/v1/receive', 400, '{"code":"destination_db_privileges"}');

        try {
            $this->handshake()->sendInfo(self::ID);
            $this->fail('A refused info chunk must stop the start');
        } catch (Exception $exception) {
            $this->assertStringStartsWith('Destination returned error: 400 - ', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('info_sent', $this->storage->loadMigrationData(self::ID));
    }

    public function testInfoChunkNeedsTheLockOfTheMigration(): void
    {
        $this->saveMigration();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migration lock is not held');
        $this->handshake()->sendInfo(self::ID);
    }
}
