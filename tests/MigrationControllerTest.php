<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Api\Controllers\MigrationController;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Services\Migration\HtaccessService;
use WordpressMigrator\Services\Migration\MigrationService;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationControllerTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Support/WordPressStubs.php';
    }

    public function testScanPassesOptionsAndConvertsSummaryKeys(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('scanAndPrepareMigration')
            ->with('https://destination.example', '*.log', 'secret', '192.0.2.1')
            ->willReturn([
                'migrationId' => 'mig_1',
                'migrationData' => ['summary' => ['totalFiles' => 2, 'nestedData' => ['rowCount' => 4]]],
            ]);
        $service->expects($this->once())->method('getMigrationFilePath')
            ->with('mig_1')->willReturn('/tmp/mig_1.json');

        $response = $this->controller($service)->scan(new \WP_REST_Request(
            ['destination_url' => 'https://destination.example'],
            [],
            ['exclusions' => '*.log', 'token' => 'secret', 'ip' => '192.0.2.1']
        ));

        $this->assertSame([
            'success' => true,
            'migration_id' => 'mig_1',
            'file_path' => '/tmp/mig_1.json',
            'summary' => ['total_files' => 2, 'nested_data' => ['row_count' => 4]],
        ], $response->get_data());
    }

    public function testScanFailureBecomesApiError(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->method('scanAndPrepareMigration')->willThrowException(new Exception('scan failed'));

        $response = $this->controller($service)->scan(new \WP_REST_Request([
            'destination_url' => 'https://destination.example',
        ]));

        $this->assertSame('scan_failed', $response->get_error_code());
        $this->assertSame('scan failed', $response->get_error_message());
        $this->assertSame(['status' => 500], $response->get_error_data());
    }

    public function testInfoReturnsRawOrConvertedData(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('getMigrationInfoRaw')
            ->with('mig_1')->willReturn(['rowCount' => 2]);
        $service->expects($this->once())->method('getMigrationInfo')
            ->with('mig_1')->willReturn(['rowCount' => 2]);
        $controller = $this->controller($service);

        $raw = $controller->info(new \WP_REST_Request(['migration_id' => 'mig_1', 'raw' => true]));
        $normal = $controller->info(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame(['rowCount' => 2], $raw->get_data());
        $this->assertSame(['row_count' => 2], $normal->get_data());
    }

    public function testInfoReturns404WhenMigrationIsMissing(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->method('getMigrationInfo')->willReturn(null);

        $response = $this->controller($service)->info(new \WP_REST_Request(['migration_id' => 'missing']));

        $this->assertSame('not_found', $response->get_error_code());
        $this->assertSame(['status' => 404], $response->get_error_data());
    }

    public function testFinalizeDistinguishesIncompleteMigrationFromOtherFailure(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->method('finalizeMigration')->willThrowException(new Exception('Migration is not complete yet'));

        $response = $this->controller($service)->finalize(new \WP_REST_Request(['migration_id' => 'mig_1']));

        $this->assertSame('not_complete', $response->get_error_code());
        $this->assertSame(['status' => 400], $response->get_error_data());
    }

    public function testReceiveHtaccessRejectsMissingAndNonStringContent(): void
    {
        $htaccess = $this->createMock(HtaccessService::class);
        $htaccess->expects($this->never())->method('receive');
        $controller = $this->controller($this->createMock(MigrationService::class), $htaccess);

        $missing = $controller->receiveHtaccess(new \WP_REST_Request([], [], []));
        $invalid = $controller->receiveHtaccess(new \WP_REST_Request([], [], ['content' => ['rule']]));

        $this->assertSame('missing_content', $missing->get_error_code());
        $this->assertSame(['status' => 400], $missing->get_error_data());
        $this->assertSame('invalid_content', $invalid->get_error_code());
        $this->assertSame(['status' => 400], $invalid->get_error_data());
    }

    public function testReceiveHtaccessAcceptsEmptyStringAndReturnsByteCount(): void
    {
        $htaccess = $this->createMock(HtaccessService::class);
        $htaccess->expects($this->once())->method('receive')->with('')->willReturn(['bytes_written' => 0]);

        $response = $this->controller($this->createMock(MigrationService::class), $htaccess)
            ->receiveHtaccess(new \WP_REST_Request([], [], ['content' => '']));

        $this->assertSame([
            'success' => true,
            'saved' => true,
            'message' => '.htaccess saved successfully',
            'bytes_written' => 0,
        ], $response->get_data());
    }

    public function testTransferHtaccessReturns404BeforeCallingService(): void
    {
        Config::set('migration_dir', $this->createTemporaryDirectory());
        $htaccess = $this->createMock(HtaccessService::class);
        $htaccess->expects($this->never())->method('transfer');

        $response = $this->controller($this->createMock(MigrationService::class), $htaccess)
            ->transferHtaccess(new \WP_REST_Request(['migration_id' => 'missing']));

        $this->assertSame('not_found', $response->get_error_code());
        $this->assertSame(['status' => 404], $response->get_error_data());
    }

    public function testCompleteRejectsMissingIdAndReturns404ForUnknownMigration(): void
    {
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('completeMigration')->with('missing')->willReturn(null);
        $controller = $this->controller($service);

        $missingId = $controller->complete(new \WP_REST_Request([], [], []));
        $unknown = $controller->complete(new \WP_REST_Request([], [], ['migration_id' => 'missing']));

        $this->assertSame('missing_id', $missingId->get_error_code());
        $this->assertSame(['status' => 400], $missingId->get_error_data());
        $this->assertSame('not_found', $unknown->get_error_code());
        $this->assertSame(['status' => 404], $unknown->get_error_data());
    }

    private function controller(MigrationService $service, ?HtaccessService $htaccess = null): MigrationController
    {
        return new MigrationController($service, $htaccess ?? $this->createMock(HtaccessService::class));
    }
}
