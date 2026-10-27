<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Helpers\TokenHelper;
use WordpressMigrator\Services\Migration\MigrationFinalizer;
use WordpressMigrator\Tests\Support\CurlOptionCapture;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationFinalizerTest extends TestCase
{
    use TemporaryDirectory;

    private string $migrationDir;
    private FakeDestination $destination;

    protected function setUp(): void
    {
        $this->migrationDir = $this->createTemporaryDirectory();
        define('WP_MIGRATOR_MIGRATION_DIR', $this->migrationDir);
        Config::set('allowed_destinations', ['127.0.0.1']);
        $this->destination = FakeDestination::get();
    }

    public function testMissingMigrationFileThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Migration file not found');

        new MigrationFinalizer('missing');
    }

    public function testCompletionSignalIncludesSummaryAndLeavesMigrationFileUnchanged(): void
    {
        $data = $this->baseData();
        $data['files'] = [
            ['status' => 'sent', 'size' => 10, 'chunks' => [['status' => 'sent'], ['status' => 'sent']]],
            ['status' => 'skipped', 'size' => 30, 'chunks' => [['status' => 'skipped']]],
        ];
        $data['tables'] = [
            ['status' => 'sent', 'size' => 100, 'rowCount' => 4, 'chunks' => [['status' => 'sent']]],
        ];
        $this->saveMigration($data);
        $before = file_get_contents($this->migrationDir . 'mig_1.json');

        $finalizer = new MigrationFinalizer('mig_1');
        $this->assertTrue($finalizer->isComplete());
        CurlOptionCapture::reset();
        $this->assertSame(['success' => true], $finalizer->sendCompletionSignal());

        $this->assertSame($before, file_get_contents($this->migrationDir . 'mig_1.json'));
        $requests = $this->destination->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/wp-json/wp-migrator/v1/collect-chunk', $requests[0]['uri']);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('application/json', $requests[0]['headers']['content-type']);
        $this->assertSame('destination-token', $requests[0]['headers']['x-migrator-token']);
        $this->assertTrue(CurlOptionCapture::peerVerificationEnabled());
        $this->assertTrue(CurlOptionCapture::hostVerificationEnabled());

        $body = json_decode($requests[0]['body'], true);
        $this->assertSame('migration_complete', $body['type']);
        $this->assertSame('mig_1', $body['migration_id']);
        $this->assertSame('completed', $body['status']);
        $this->assertSame($data['timestamp'], $body['start_time']);
        $this->assertNotEmpty($body['end_time']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $body['duration']);
        $this->assertSame([
            'files' => ['total' => 2, 'sent' => 1, 'failed' => 0, 'skipped' => 1, 'total_size' => 40],
            'tables' => ['total' => 1, 'sent' => 1, 'failed' => 0, 'total_rows' => 4, 'total_size' => 100],
            'chunks' => ['total' => 3, 'sent' => 3, 'failed' => 0],
        ], $body['summary']);
    }

    public function testCompletionSignalSendsTheSkippedFilesAndReturnsAWarning(): void
    {
        $data = $this->baseData();
        $data['files'] = [
            ['path' => 'a.txt', 'status' => 'sent', 'size' => 10, 'chunks' => [['status' => 'sent']]],
            ['path' => 'cache/x.log', 'status' => 'skipped', 'size' => 30, 'chunks' => [['status' => 'skipped']]],
        ];
        $this->saveMigration($data);

        $result = (new MigrationFinalizer('mig_1'))->sendCompletionSignal();

        $body = json_decode($this->destination->requests()[0]['body'], true);
        $this->assertSame(['cache/x.log'], $body['skipped_files']);
        $this->assertTrue($result['success']);
        $this->assertSame(['cache/x.log'], $result['skipped_files']);
        $this->assertNotEmpty($result['warning']);
    }

    public function testCompletionSignalWithoutSkippedFilesHasNoWarning(): void
    {
        $this->saveMigration($this->baseData());

        $result = (new MigrationFinalizer('mig_1'))->sendCompletionSignal();

        $this->assertSame([], json_decode($this->destination->requests()[0]['body'], true)['skipped_files']);
        $this->assertSame(['success' => true], $result);
    }

    public function testCompletionSignalConnectsToTheStoredPinnedIp(): void
    {
        Config::set('allowed_destinations', []);
        $data = $this->baseData();
        $data['destinationUrl'] = 'http://destination.invalid:' . $this->destination->port();
        $data['pinned_ip'] = '127.0.0.1';
        $this->saveMigration($data);
        CurlOptionCapture::reset();

        $this->assertSame(['success' => true], (new MigrationFinalizer('mig_1'))->sendCompletionSignal());

        $this->assertSame(
            ['destination.invalid:' . $this->destination->port() . ':127.0.0.1'],
            CurlOptionCapture::get(CURLOPT_RESOLVE)
        );
    }

    public static function tlsScenarios(): array
    {
        return [
            'manual ip and insecure_tls' => [['ip' => '127.0.0.1', 'insecure_tls' => true], false],
            'insecure_tls without a manual ip' => [['insecure_tls' => true], true],
            'manual ip, insecure_tls off' => [['ip' => '127.0.0.1', 'insecure_tls' => false], true],
            'manual ip, insecure_tls as the string false' => [['ip' => '127.0.0.1', 'insecure_tls' => 'false'], true],
            'manual ip, insecure_tls missing' => [['ip' => '127.0.0.1'], true],
            'default' => [[], true],
        ];
    }

    /**
     * @dataProvider tlsScenarios
     */
    #[DataProvider('tlsScenarios')]
    public function testCompletionSignalSkipsCertificateChecksOnlyForAManualIpWithInsecureTls(array $tlsData, bool $strict): void
    {
        $this->saveMigration(array_merge($this->baseData(), $tlsData));
        CurlOptionCapture::reset();

        $this->assertSame(['success' => true], (new MigrationFinalizer('mig_1'))->sendCompletionSignal());

        $this->assertSame($strict, CurlOptionCapture::peerVerificationEnabled());
        $this->assertSame($strict, CurlOptionCapture::hostVerificationEnabled());
    }

    public function testPartialTransferIsNotReportedAsComplete(): void
    {
        $data = $this->baseData();
        $data['files'] = [
            ['status' => 'sent', 'size' => 10, 'chunks' => [['status' => 'sent'], ['status' => 'pending']]],
        ];
        $this->saveMigration($data);

        $finalizer = new MigrationFinalizer('mig_1');

        $this->assertFalse($finalizer->isComplete());
        try {
            $finalizer->sendCompletionSignal();
            $this->fail('A partial transfer must not be finalized as a success');
        } catch (Exception $exception) {
            $this->assertSame('Migration is not complete yet', $exception->getMessage());
        }

        $this->assertSame([], $this->destination->requests());
    }

    public function testMissingDestinationThrowsBeforeHttpRequest(): void
    {
        $data = $this->baseData();
        unset($data['destinationUrl']);
        $this->saveMigration($data);

        try {
            (new MigrationFinalizer('mig_1'))->sendCompletionSignal();
            $this->fail('Expected a missing destination error');
        } catch (Exception $exception) {
            $this->assertSame('Destination URL is not defined.', $exception->getMessage());
        }

        $this->assertSame([], $this->destination->requests());
    }

    public function testServerErrorIncludesStatusAndResponseBody(): void
    {
        $this->saveMigration($this->baseData());
        $this->destination->respondWith('/collect-chunk', 503, '{"message":"busy"}');

        try {
            (new MigrationFinalizer('mig_1'))->sendCompletionSignal();
            $this->fail('Expected destination error');
        } catch (Exception $exception) {
            $this->assertSame('Destination returned error: 503 - {"message":"busy"}', $exception->getMessage());
        }

        $this->assertCount(1, $this->destination->requests());
    }

    public function testLongErrorBodyIsCutInTheMessage(): void
    {
        $this->saveMigration($this->baseData());
        $this->destination->respondWith('/collect-chunk', 503, str_repeat('z', 1000));

        try {
            (new MigrationFinalizer('mig_1'))->sendCompletionSignal();
            $this->fail('Expected destination error');
        } catch (Exception $exception) {
            $this->assertSame('Destination returned error: 503 - ' . str_repeat('z', 300), $exception->getMessage());
        }
    }

    public function testManualIpThatIsNotOneValidIpIsRefusedBeforeAnyRequest(): void
    {
        $this->saveMigration(array_merge($this->baseData(), ['ip' => '127.0.0.1,127.0.0.2']));

        try {
            (new MigrationFinalizer('mig_1'))->sendCompletionSignal();
            $this->fail('Expected the destination to be refused');
        } catch (\WordpressMigrator\Helpers\DestinationNotAllowedException $exception) {
            $this->assertSame([], $this->destination->requests());
        }
    }

    public function testNonJsonAnswerOnThePrettyRouteFallsBackToTheRestRouteQuery(): void
    {
        $this->saveMigration($this->baseData());
        $this->destination->respondWith('/wp-json/', 200, '<html>home page</html>');

        $this->assertSame(['success' => true], (new MigrationFinalizer('mig_1'))->sendCompletionSignal());

        $requests = $this->destination->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('/wp-json/wp-migrator/v1/collect-chunk', $requests[0]['uri']);
        $this->assertSame('/?rest_route=/wp-migrator/v1/collect-chunk', $requests[1]['uri']);
        $this->assertSame($requests[0]['body'], $requests[1]['body']);
        $this->assertSame('destination-token', $requests[1]['headers']['x-migrator-token']);
    }

    public function test404OnThePrettyRouteFallsBackToTheRestRouteQuery(): void
    {
        $this->saveMigration($this->baseData());
        $this->destination->respondWith('/wp-json/', 404, '{"code":"rest_no_route"}');

        $this->assertSame(['success' => true], (new MigrationFinalizer('mig_1'))->sendCompletionSignal());

        $this->assertCount(2, $this->destination->requests());
    }

    public function testHtmlAnswerOnBothRoutesIsNotAConfirmation(): void
    {
        $this->saveMigration($this->baseData());
        $this->destination->respondWith('collect-chunk', 200, '<html>home page</html>');

        $this->assertNull((new MigrationFinalizer('mig_1'))->sendCompletionSignal());

        $this->assertCount(2, $this->destination->requests());
    }

    public function testAcknowledgementFromADestinationBefore120CountsAsConfirmation(): void
    {
        $this->saveMigration($this->baseData());
        $legacy = [
            'type' => 'acknowledgment',
            'migration_id' => 'mig_1',
            'chunk_id' => null,
            'status' => 'received',
            'processed' => true,
            'error' => null,
            'processing_result' => ['action' => 'migration_completed'],
        ];
        $this->destination->respondWith('collect-chunk', 200, json_encode($legacy));

        $result = (new MigrationFinalizer('mig_1'))->sendCompletionSignal();

        $this->assertTrue($result['success']);
        $this->assertSame('migration_completed', $result['processing_result']['action']);
        $this->assertCount(1, $this->destination->requests());
    }

    public function testAcknowledgementForAnotherMigrationIsNotAConfirmation(): void
    {
        $this->saveMigration($this->baseData());
        $this->destination->respondWith('collect-chunk', 200, json_encode([
            'type' => 'acknowledgment',
            'migration_id' => 'other',
            'status' => 'received',
            'processed' => true,
        ]));

        $result = (new MigrationFinalizer('mig_1'))->sendCompletionSignal();

        $this->assertArrayNotHasKey('success', $result);
    }

    public function testConnectionFailureIsReported(): void
    {
        $data = $this->baseData();
        $data['destinationUrl'] = FakeDestination::closedPortUrl();
        $this->saveMigration($data);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to send completion signal:');
        (new MigrationFinalizer('mig_1'))->sendCompletionSignal();
    }

    public function testMigrationTokenIgnoresTheCompressedTypeKey(): void
    {
        $this->assertSame('long', TokenHelper::forMigration(['token' => 'long', 't' => 'info']));

        $this->expectException(\RuntimeException::class);
        TokenHelper::forMigration(['t' => 'info']);
    }

    public function testCompletionNeverSendsTheSiteTokenToTheDestination(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'config.php', "<?php\nreturn ['token' => 'config-token'];\n");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);
        $data = $this->baseData();
        unset($data['token']);
        $this->saveMigration($data);

        try {
            (new MigrationFinalizer('mig_1'))->sendCompletionSignal();
            $this->fail('A migration without a destination token must not be sent');
        } catch (\RuntimeException $e) {
            $this->assertSame('Destination token not found in migration data', $e->getMessage());
        }

        $this->assertSame([], $this->destination->requests());
    }

    private function baseData(): array
    {
        return [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'destination-token',
            'timestamp' => '2026-09-25T10:00:00+00:00',
            'files' => [],
            'tables' => [],
        ];
    }

    private function saveMigration(array $data): void
    {
        self::writeFile($this->migrationDir . 'mig_1.json', JsonCompressor::compressToJson($data));
    }
}
