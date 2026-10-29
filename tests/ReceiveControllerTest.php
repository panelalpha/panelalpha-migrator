<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Api\Controllers\ReceiveController;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Restore\ReceiveFinalizer;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;
use PanelAlpha\Migrator\Tests\Support\ReceivesMigration;
use PanelAlpha\Migrator\Tests\Support\ResetsGlobalState;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReceiveControllerTest extends TestCase
{
    use ReceivesMigration;
    use ResetsGlobalState;
    use TemporaryDirectory;

    private const ID = 'mig_20260101_000000_0123456789abcdef0123456789abcdef';
    private const OTHER_ID = 'mig_20260102_000000_fedcba9876543210fedcba9876543210';
    private const TOKEN = 'destination-token';

    private ReceiveController $controller;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
        require_once __DIR__ . '/Support/OptionStubs.php';
        update_option(TokenHelper::TOKEN_OPTION, self::TOKEN, false);
        define('PANELALPHA_MIGRATOR_PLUGIN_DIR', $this->createTemporaryDirectory());
        $this->setUpDestination();
        $this->controller = new ReceiveController($this->wpdb, $this->abspath);
    }

    private function post(array $chunk, array $headers = []): \WP_REST_Request
    {
        return new \WP_REST_Request([], $headers + ['X-Migrator-Token' => self::TOKEN], null, json_encode($chunk));
    }

    private function assertError($response, string $code, int $status): void
    {
        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame($code, $response->get_error_code());
        $this->assertSame($status, $response->get_error_data()['status']);
    }

    public function testOnlyTheTokenInTheHeaderOpensTheRoutes(): void
    {
        $this->assertTrue(ReceiveController::authorize(new \WP_REST_Request([], ['X-Migrator-Token' => self::TOKEN])));

        foreach ([
            new \WP_REST_Request(),
            new \WP_REST_Request([], ['X-Migrator-Token' => 'wrong']),
            new \WP_REST_Request([], ['X-Migrator-Token' => '']),
            new \WP_REST_Request(['token' => self::TOKEN]),
        ] as $request) {
            $denied = ReceiveController::authorize($request);

            $this->assertSame('rest_forbidden', $denied->get_error_code());
            $this->assertSame(401, $denied->get_error_data()['status']);
        }
    }

    public function testADestinationWithoutATokenAnswers401(): void
    {
        update_option(TokenHelper::TOKEN_OPTION, '', false);

        $denied = ReceiveController::authorize(new \WP_REST_Request([], ['X-Migrator-Token' => '']));

        $this->assertSame(401, $denied->get_error_data()['status']);
        $this->assertSame('Destination token is not configured', $denied->get_error_message());
    }

    public function testCheckReportsTheReceiveVersionAndIsNotCached(): void
    {
        $response = $this->controller->check(new \WP_REST_Request());

        $this->assertSame(2, $response->get_data()['receive']);
        $this->assertTrue($response->get_data()['success']);
        $this->assertArrayHasKey('free_disk_space', $response->get_data());
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
    }

    public function testDataChunksBeforeTheInfoChunkAreRefusedWith409(): void
    {
        $this->assertError($this->controller->receive($this->post($this->structureChunk(self::ID))), 'migration_conflict', 409);
        $this->assertError($this->controller->receive($this->post($this->fileChunk(self::ID))), 'migration_conflict', 409);
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/demo/demo.php');
    }

    public function testATableOutsideTheMapIsRefusedWith409(): void
    {
        $this->controller->receive($this->post($this->infoChunk(self::ID)));
        $chunk = $this->structureChunk(self::ID);
        $chunk['table']['name'] = 'wp_unknown';

        $this->assertError($this->controller->receive($this->post($chunk)), 'migration_conflict', 409);
    }

    public function testTheInfoChunkRegistersTheMigrationAndIsAcknowledged(): void
    {
        $response = $this->controller->receive($this->post($this->infoChunk(self::ID) + ['chunk_id' => 'info_1']));

        $data = $response->get_data();
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['success']);
        $this->assertTrue($data['processed']);
        $this->assertSame(self::ID, $data['migration_id']);
        $this->assertSame('info', $data['chunk_type']);
        $this->assertSame('info_1', $data['chunk_id']);
        $this->assertSame('registered_migration', $data['result']['action']);
        $this->assertSame('collecting', (new MigrationRegistry(self::ID))->getStatus()['status']);
        $this->assertNotNull((new MigrationRegistry(self::ID))->getTableHash());
    }

    public function testChunksAreAcceptedAsBase64(): void
    {
        $request = new \WP_REST_Request(
            [],
            ['X-Migrator-Token' => self::TOKEN, 'X-Migrator-Encoding' => 'base64'],
            null,
            base64_encode(json_encode($this->infoChunk(self::ID)))
        );

        $this->assertTrue($this->controller->receive($request)->get_data()['success']);
    }

    public function testAFailedChunkIsNotAcknowledgedAsDelivered(): void
    {
        $this->controller->receive($this->post($this->infoChunk(self::ID)));
        $chunk = $this->fileChunk(self::ID);
        $chunk['file']['chunk_info']['chunk_number'] = 1;
        $chunk['file']['path'] = 'wp-content/plugins/demo/demo.php';
        // The staging folder cannot be created below a regular file.
        file_put_contents($this->migrationDir . self::ID, 'not a folder');

        $response = $this->controller->receive($this->post($chunk));

        $this->assertFalse($response->get_data()['success']);
        $this->assertTrue($response->get_data()['result']['failed']);
    }

    public function testBadRequestsAnswer400(): void
    {
        $this->assertError($this->controller->receive(new \WP_REST_Request([], [], null, '{not json')), 'invalid_json', 400);
        $this->assertError($this->controller->receive($this->post(['type' => 'info'])), 'invalid_id', 400);
        $this->assertError($this->controller->receive($this->post(['type' => 'info', 'migration_id' => '../x'])), 'invalid_id', 400);
        $this->assertError($this->controller->receive($this->post(['type' => 'table_data', 'migration_id' => self::ID, 'chunk_id' => '../x'])), 'invalid_id', 400);
        $this->assertError($this->controller->receive($this->post(['type' => 'migration_complete', 'migration_id' => self::ID])), 'invalid_chunk', 400);
        $this->assertError($this->controller->receive($this->post($this->infoChunk(self::ID, ['tables' => [['total_chunks' => 1]]]))), 'invalid_chunk', 400);
        $this->assertError($this->controller->receive($this->post($this->infoChunk(self::ID, ['files' => 'x']))), 'invalid_chunk', 400);
    }

    public function testADatabaseUserWithoutPrivilegesIsRefusedAndTheMigrationDoesNotStart(): void
    {
        $this->wpdb->failOn = 'CREATE TABLE';

        $response = $this->controller->receive($this->post($this->infoChunk(self::ID)));

        $this->assertError($response, 'destination_db_privileges', 400);
        $this->assertStringContainsString('needs CREATE, ALTER, DROP', $response->get_error_message());
        $this->assertNull((new MigrationRegistry(self::ID))->getStatus());
    }

    public function testNotEnoughDiskSpaceIsRefusedWithBothNumbers(): void
    {
        $info = $this->infoChunk(self::ID, ['files' => [['path' => 'a.bin', 'size' => PHP_INT_MAX / 4, 'total_chunks' => 1]]]);

        $response = $this->controller->receive($this->post($info));

        $this->assertError($response, 'insufficient_disk_space', 400);
        $this->assertMatchesRegularExpression('/\d+ bytes free, \d+ bytes needed/', $response->get_error_message());
        $this->assertNull((new MigrationRegistry(self::ID))->getStatus());
    }

    public function testANewInfoChunkResetsAnAbandonedMigration(): void
    {
        $this->receiveWholeMigration(self::ID);
        $abandoned = (new MigrationRegistry(self::ID))->getTableMap()['wp_options']['temp'];
        $this->assertDirectoryExists($this->migrationDir . self::ID . '/staged');

        $this->controller->receive($this->post($this->infoChunk(self::OTHER_ID)));

        $this->assertContains("DROP TABLE IF EXISTS `{$abandoned}`", $this->wpdb->queries);
        $this->assertDirectoryDoesNotExist($this->migrationDir . self::ID);
        $this->assertSame('abandoned', (new MigrationRegistry(self::ID))->getStatus()['status']);
        $this->assertSame('collecting', (new MigrationRegistry(self::OTHER_ID))->getStatus()['status']);
        $this->assertError($this->controller->receive($this->post($this->fileChunk(self::ID))), 'migration_conflict', 409);
        $this->assertError($this->controller->finalize($this->post(['migration_id' => self::ID])), 'migration_conflict', 409);
    }

    public function testStatusReportsAKnownMigrationAndIsNotCached(): void
    {
        $this->controller->receive($this->post($this->infoChunk(self::ID)));

        $response = $this->controller->status(new \WP_REST_Request(['migration_id' => self::ID]));

        $this->assertSame('collecting', $response->get_data()['status']['status']);
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
    }

    public function testStatusOfAnUnknownMigrationIs404(): void
    {
        $this->assertError($this->controller->status(new \WP_REST_Request(['migration_id' => self::ID])), 'not_found', 404);
        $this->assertError($this->controller->status(new \WP_REST_Request()), 'missing_id', 400);
        $this->assertError($this->controller->status(new \WP_REST_Request(['migration_id' => '../x'])), 'invalid_id', 400);
    }

    public function testFinalizeAnswers200AfterTheSwapAndReportsTheStateInStatus(): void
    {
        $this->receiveWholeMigration(self::ID);

        $response = $this->controller->finalize($this->post(['type' => 'migration_complete', 'migration_id' => self::ID, 'skipped_files' => []]));

        $data = $response->get_data();
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['success']);
        $this->assertTrue($data['finalized']);
        $this->assertSame('migration_completed', $data['result']['action']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/demo/demo.php'));

        $status = $this->controller->status(new \WP_REST_Request(['migration_id' => self::ID]))->get_data();
        $this->assertSame('restored', $status['status']['status']);
        $this->assertSame('done', $status['finalize']);
    }

    public function testFinalizeWhileAnotherCallIsFinishingAnswers202(): void
    {
        $this->receiveWholeMigration(self::ID);
        $lock = fopen($this->migrationDir . 'collected/' . self::ID . '/status.lock', 'c');
        flock($lock, LOCK_EX);

        $response = $this->controller->finalize($this->post(['migration_id' => self::ID]));

        flock($lock, LOCK_UN);
        $this->assertSame(202, $response->get_status());
        $this->assertSame(
            ['success' => true, 'finalized' => false, 'phase' => 'finalizing', 'migration_id' => self::ID],
            $response->get_data()
        );
    }

    public function testFinalizeErrors(): void
    {
        $this->assertError($this->controller->finalize($this->post(['migration_id' => self::ID])), 'not_found', 404);
        $this->assertError($this->controller->finalize($this->post([])), 'invalid_id', 400);

        $this->controller->receive($this->post($this->infoChunk(self::ID)));
        $this->assertError($this->controller->finalize($this->post(['migration_id' => self::ID])), 'migration_conflict', 409);

        $this->receiveWholeMigration(self::OTHER_ID);
        $this->wpdb->failOn = 'RENAME TABLE';
        $failed = $this->controller->finalize($this->post(['migration_id' => self::OTHER_ID]));
        $this->assertError($failed, 'finalize_failed', 500);
        $this->assertStringContainsString('Failed to swap the tables', $failed->get_error_message());
        $this->assertSame('failed', ReceiveFinalizer::readJournal(self::OTHER_ID)['state']);
    }
}
