<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Helpers\JsonCompressor;
use PanelAlpha\Migrator\Services\Backup\FileBackupService;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Tests\Support\CurlOptionCapture;
use PanelAlpha\Migrator\Tests\Support\FakeDestination;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class FileBackupServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $siteDir;

    private FakeDestination $destination;

    private MigrationStorageService $storage;

    protected function setUp(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        Config::set('allowed_destinations', ['127.0.0.1']);
        $this->siteDir = $this->createTemporaryDirectory();
        Config::set('root_path', $this->siteDir);
        $this->destination = FakeDestination::get();
        $this->storage = new MigrationStorageService();
    }

    public function testConstructorThrowsWhenMigrationFileIsMissing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Migration file not found');

        new FileBackupService('missing_mig');
    }

    public function testConstructorThrowsWhenDestinationUrlIsMissing(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'token' => 'secret',
            'files' => [],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Destination URL not found in migration data');

        new FileBackupService('mig_1');
    }

    public function testDestinationUrlIsReadFromCamelCaseKey(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertCount(1, $this->destination->requests());
    }

    public function testDestinationUrlIsReadFromSnakeCaseKey(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destination_url' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertCount(1, $this->destination->requests());
    }

    public function testTokenComesFromMigrationData(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'from-migration',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame('from-migration', $this->destination->requests()[0]['headers']['x-migrator-token']);
    }

    /**
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testSiteTokenIsNeverSentWhenTheMigrationHasNoDestinationToken(): void
    {
        require __DIR__ . '/Support/OptionStubs.php';
        update_option(TokenHelper::TOKEN_OPTION, 'site-token', false);
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        try {
            (new FileBackupService('mig_1'))->getNextChunk();
            $this->fail('A migration without a destination token must not be sent');
        } catch (\RuntimeException $e) {
            $this->assertSame('Destination token not found in migration data', $e->getMessage());
        }

        $this->assertSame([], $this->destination->requests());
    }

    public function testChunkFollowsTheRestModeSavedWithTheMigration(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello world');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'drm' => 'plain',
            'files' => [$this->fileEntry('a.txt', 'hello world', 11)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame('/?rest_route=/panelalpha-migrator/v1/receive', $this->destination->requests()[0]['uri']);
    }

    public function testSinglePendingChunkIsSentAndMarkedSent(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello world');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello world', 11)],
        ]);

        $result = (new FileBackupService('mig_1'))->getNextChunk();

        $request = $this->destination->requests()[0];
        $this->assertSame('/wp-json/panelalpha-migrator/v1/receive', $request['uri']);
        $this->assertSame('secret', $request['headers']['x-migrator-token']);

        $body = JsonCompressor::decompressFromJson($request['body']);
        $this->assertSame('file_chunk', $body['type']);
        $this->assertSame('mig_1', $body['migration_id']);
        $this->assertSame('file_chunk_00001', $body['chunk_id']);
        $this->assertSame('a.txt', $body['file']['path']);
        $this->assertSame(base64_encode('hello world'), $body['content']);
        $this->assertSame(hash('sha256', 'hello world'), $body['checksum']);
        $this->assertSame([
            'chunk_number' => 1,
            'total_chunks' => 1,
            'offset' => 0,
            'size' => 11,
        ], $body['file']['chunk_info']);

        $this->assertArrayNotHasKey('content', $result);
        $this->assertSame('file_chunk_00001', $result['chunk_id']);

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('sent', $saved['files'][0]['status']);
        $this->assertSame('sent', $saved['files'][0]['chunks'][0]['status']);
    }

    public function testMultiChunkFileGoesInProgressThenSentWithBytesAtTheRightOffsets(): void
    {
        $content = '0123456789';
        self::writeFile($this->siteDir . 'big.bin', $content);
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('big.bin', $content, 6)],
        ]);
        $service = new FileBackupService('mig_1');

        $service->getNextChunk();

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('in_progress', $saved['files'][0]['status']);
        $this->assertSame('sent', $saved['files'][0]['chunks'][0]['status']);
        $this->assertSame('pending', $saved['files'][0]['chunks'][1]['status']);
        $firstBody = JsonCompressor::decompressFromJson($this->destination->requests()[0]['body']);
        $this->assertSame(base64_encode('012345'), $firstBody['content']);
        $this->assertSame('file_chunk_00001', $firstBody['chunk_id']);

        $service->getNextChunk();

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('sent', $saved['files'][0]['status']);
        $this->assertSame('sent', $saved['files'][0]['chunks'][1]['status']);
        $secondBody = JsonCompressor::decompressFromJson($this->destination->requests()[1]['body']);
        $this->assertSame(base64_encode('6789'), $secondBody['content']);
        $this->assertSame('file_chunk_00002', $secondBody['chunk_id']);
    }

    public function testReturnsNullWhenNothingIsPending(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hi');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hi', 2)],
        ]);
        $service = new FileBackupService('mig_1');

        $service->getNextChunk();

        $this->assertNull($service->getNextChunk());
    }

    public function testBase64ModeSendsOctetStream(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'base64' => true,
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);

        (new FileBackupService('mig_1'))->getNextChunk();

        $request = $this->destination->requests()[0];
        $this->assertSame('application/octet-stream', $request['headers']['content-type']);
        $this->assertSame('base64', $request['headers']['x-migrator-encoding']);
        $decoded = base64_decode($request['body'], true);
        $body = JsonCompressor::decompressFromJson($decoded);
        $this->assertSame(base64_encode('hello'), $body['content']);
    }

    public function testDeletedFileIsSkippedAndTheNextFileContinues(): void
    {
        self::writeFile($this->siteDir . 'present.txt', 'hi');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [
                $this->fileEntry('missing.txt', 'xxxxx', 5),
                $this->fileEntry('present.txt', 'hi', 2),
            ],
        ]);

        $result = (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame('present.txt', $result['file']['path']);
        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('skipped', $saved['files'][0]['status']);
        $this->assertSame('skipped', $saved['files'][0]['chunks'][0]['status']);
        $this->assertSame('sent', $saved['files'][1]['chunks'][0]['status']);
    }

    public function testFailedAcknowledgementLeavesTheChunkPending(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);
        $this->destination->respondWith('/v1/receive', 200, json_encode([
            'success' => true,
            'migration_id' => 'mig_1',
            'chunk_id' => 'file_chunk_00001',
            'result' => ['failed' => true, 'can_retry' => true, 'reason' => 'disk full'],
        ]));

        $service = new FileBackupService('mig_1');

        try {
            $service->getNextChunk();
            $this->fail('A failed write must not count as delivered');
        } catch (Exception $e) {
            $this->assertStringContainsString('disk full', $e->getMessage());
        }

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('pending', $saved['files'][0]['status']);
        $this->assertSame('pending', $saved['files'][0]['chunks'][0]['status']);
        $this->assertFalse($service->getProgress()['is_complete']);
    }

    public function testExplicitSkipIsRecordedWithItsReason(): void
    {
        self::writeFile($this->siteDir . 'wp-config.php', '<?php');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('wp-config.php', '<?php', 5)],
        ]);
        $this->destination->respondWith('/v1/receive', 200, json_encode([
            'success' => true,
            'migration_id' => 'mig_1',
            'chunk_id' => 'file_chunk_00001',
            'result' => [
                'skipped' => true,
                'can_retry' => false,
                'reason' => 'Critical file excluded from restore',
            ],
        ]));

        (new FileBackupService('mig_1'))->getNextChunk();

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('skipped', $saved['files'][0]['status']);
        $this->assertSame('skipped', $saved['files'][0]['chunks'][0]['status']);
        $this->assertSame('Critical file excluded from restore', $saved['files'][0]['chunks'][0]['skip_reason']);
    }

    public function testHttpErrorThrowsAndLeavesTheChunkPending(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);
        $this->destination->respondWith('/v1/receive', 500, '{"error":"boom"}');

        $service = new FileBackupService('mig_1');

        try {
            $service->getNextChunk();
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Destination returned error: 500', $e->getMessage());
        }

        $saved = $this->storage->loadMigrationData('mig_1');
        $this->assertSame('pending', $saved['files'][0]['status']);
        $this->assertSame('pending', $saved['files'][0]['chunks'][0]['status']);
    }

    public function testProgressIncludesPartiallySentInProgressFile(): void
    {
        $fileA = $this->fileEntry('a.txt', '0123456789', 10);
        $fileA['status'] = 'sent';
        $fileA['chunks'][0]['status'] = 'sent';

        $fileB = $this->fileEntry('b.txt', '0123456789', 5);
        $fileB['status'] = 'in_progress';
        $fileB['chunks'][0]['status'] = 'sent';

        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$fileA, $fileB],
        ]);

        $progress = (new FileBackupService('mig_1'))->getProgress();

        $this->assertSame(3, $progress['total_chunks']);
        $this->assertSame(2, $progress['sent_chunks']);
        $this->assertSame(2, $progress['total_files']);
        $this->assertSame(1, $progress['completed_files']);
        $this->assertEqualsWithDelta(66.67, $progress['percentage'], 0.01);
        $this->assertFalse($progress['is_complete']);
        $this->assertSame('b.txt', $progress['current_file']);
        $this->assertSame(20, $progress['total_size']);
        $this->assertSame(15, $progress['sent_size']);
    }

    public function testProgressCurrentFileCanBeOverridden(): void
    {
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hi', 2)],
        ]);

        $progress = (new FileBackupService('mig_1'))->getProgress('custom/override.txt');

        $this->assertSame('custom/override.txt', $progress['current_file']);
    }

    public function testMigrationWithoutPinnedIpGetsOneOnTheFirstChunkAndIsNotCheckedAgain(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello world');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello world', 5)],
        ]);
        $service = new FileBackupService('mig_1');

        $service->getNextChunk();

        $this->assertSame('127.0.0.1', $this->storage->loadMigrationData('mig_1')['pinned_ip']);

        // 127.0.0.1 would now be refused, so the next chunk proves the guard does not run again.
        Config::set('allowed_destinations', []);
        $service->getNextChunk();

        $this->assertCount(2, $this->destination->requests());
    }

    public function testStoredPinnedIpSkipsTheGuardAndIsUsedForTheConnection(): void
    {
        Config::set('allowed_destinations', []);
        self::writeFile($this->siteDir . 'a.txt', 'hello world');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => 'http://destination.invalid:' . $this->destination->port(),
            'pinned_ip' => '127.0.0.1',
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello world', 11)],
        ]);
        CurlOptionCapture::reset();

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame(
            ['destination.invalid:' . $this->destination->port() . ':127.0.0.1'],
            CurlOptionCapture::get(CURLOPT_RESOLVE)
        );
        $this->assertCount(1, $this->destination->requests());
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
    public function testChunkSkipsCertificateChecksOnlyForAManualIpWithInsecureTls(array $tlsData, bool $strict): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello world');
        $this->storage->saveMigrationData('mig_1', array_merge([
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello world', 11)],
        ], $tlsData));
        CurlOptionCapture::reset();

        (new FileBackupService('mig_1'))->getNextChunk();

        $this->assertSame($strict, CurlOptionCapture::peerVerificationEnabled());
        $this->assertSame($strict, CurlOptionCapture::hostVerificationEnabled());
    }

    private function fileEntry(string $path, string $content, int $chunkSize, string $status = 'pending'): array
    {
        $size = strlen($content);
        $chunks = [];
        $offset = 0;
        $id = 1;

        while ($offset < $size) {
            $length = min($chunkSize, $size - $offset);
            $chunks[] = ['id' => $id, 'offset' => $offset, 'size' => $length, 'status' => 'pending'];
            $offset += $length;
            $id++;
        }

        if ($chunks === []) {
            $chunks[] = ['id' => 1, 'offset' => 0, 'size' => 0, 'status' => 'pending'];
        }

        return [
            'path' => $path,
            'size' => $size,
            'permissions' => '644',
            'modified' => '2024-01-01T00:00:00+00:00',
            'chunks' => $chunks,
            'totalChunks' => count($chunks),
            'status' => $status,
        ];
    }

    public function testABatchSavesTheMigrationFileOnceAndKeepsEverySentChunk(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        self::writeFile($this->siteDir . 'b.txt', 'world');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5), $this->fileEntry('b.txt', 'world', 5)],
        ]);
        $storage = new class extends MigrationStorageService {
            public int $saves = 0;

            public function saveMigrationData(string $migrationId, array $migrationDataArray): void
            {
                $this->saves++;
                parent::saveMigrationData($migrationId, $migrationDataArray);
            }
        };
        $backup = new FileBackupService('mig_1', $storage);

        $sent = $backup->inBatch(function () use ($backup): int {
            $count = 0;
            while ($backup->getNextChunk() !== null) {
                $count++;
            }
            return $count;
        });

        $this->assertSame(2, $sent);
        $this->assertSame(1, $storage->saves);
        $files = $this->storage->loadMigrationData('mig_1')['files'];
        $this->assertSame(['sent', 'sent'], array_column($files, 'status'));
    }

    public function testAFailedBatchStillSavesTheChunksSentBeforeTheFailure(): void
    {
        self::writeFile($this->siteDir . 'a.txt', 'hello');
        $this->storage->saveMigrationData('mig_1', [
            'migrationId' => 'mig_1',
            'destinationUrl' => $this->destination->url(),
            'token' => 'secret',
            'files' => [$this->fileEntry('a.txt', 'hello', 5)],
        ]);
        $backup = new FileBackupService('mig_1');

        try {
            $backup->inBatch(function () use ($backup): void {
                $backup->getNextChunk();
                throw new \RuntimeException('stop');
            });
        } catch (\RuntimeException $e) {
        }

        $this->assertSame('sent', $this->storage->loadMigrationData('mig_1')['files'][0]['status']);
    }
}
