<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Services\Migration\MigrationFinalizer;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

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
            ['status' => 'sent', 'size' => 10, 'chunks' => [['status' => 'sent'], ['status' => 'failed']]],
            ['status' => 'failed', 'size' => 20, 'chunks' => [['status' => 'skipped'], ['status' => 'sent']]],
            ['status' => 'skipped', 'size' => 30, 'chunks' => [['status' => 'skipped']]],
        ];
        $data['tables'] = [
            ['status' => 'sent', 'size' => 100, 'rowCount' => 4, 'chunks' => [['status' => 'sent'], ['status' => 'failed']]],
            ['status' => 'failed', 'size' => 200, 'rowCount' => 6, 'chunks' => [['status' => 'failed']]],
        ];
        $this->saveMigration($data);
        $before = file_get_contents($this->migrationDir . 'mig_1.json');

        $finalizer = new MigrationFinalizer('mig_1');
        $this->assertTrue($finalizer->isComplete());
        $this->assertSame(['success' => true], $finalizer->sendCompletionSignal());

        $this->assertSame($before, file_get_contents($this->migrationDir . 'mig_1.json'));
        $requests = $this->destination->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/wp-json/wp-migrator/v1/collect-chunk', $requests[0]['uri']);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('application/json', $requests[0]['headers']['content-type']);

        $body = json_decode($requests[0]['body'], true);
        $this->assertSame('migration_complete', $body['type']);
        $this->assertSame('mig_1', $body['migration_id']);
        $this->assertSame('completed', $body['status']);
        $this->assertSame($data['timestamp'], $body['start_time']);
        $this->assertNotEmpty($body['end_time']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $body['duration']);
        $this->assertSame([
            'files' => ['total' => 3, 'sent' => 1, 'failed' => 1, 'skipped' => 1, 'total_size' => 60],
            'tables' => ['total' => 2, 'sent' => 1, 'failed' => 1, 'total_rows' => 10, 'total_size' => 300],
            'chunks' => ['total' => 6, 'sent' => 3, 'failed' => 3],
        ], $body['summary']);
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

    public function testConnectionFailureIsReported(): void
    {
        $data = $this->baseData();
        $data['destinationUrl'] = FakeDestination::closedPortUrl();
        $this->saveMigration($data);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to send completion signal:');
        (new MigrationFinalizer('mig_1'))->sendCompletionSignal();
    }

    private function baseData(): array
    {
        return [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
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
