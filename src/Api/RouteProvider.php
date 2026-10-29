<?php

namespace PanelAlpha\Migrator\Api;

use PanelAlpha\Migrator\Api\Controllers\MigrationController;
use PanelAlpha\Migrator\Api\Controllers\BackupController;
use PanelAlpha\Migrator\Api\Controllers\ReceiveController;
use PanelAlpha\Migrator\Api\Controllers\TokenController;
use PanelAlpha\Migrator\Api\Controllers\RemoteSyncOrchestrationController;
use PanelAlpha\Migrator\Services\Backup\BackupService;
use PanelAlpha\Migrator\Services\Migration\MigrationService;
use PanelAlpha\Migrator\Services\Migration\HtaccessService;
use PanelAlpha\Migrator\Services\Migration\MigrationStorageService;
use PanelAlpha\Migrator\Services\RemoteSync\RemoteSyncOrchestrationService;
use PanelAlpha\Migrator\Services\Scanner\FileScanner;
use PanelAlpha\Migrator\Services\Scanner\DatabaseScanner;
use PanelAlpha\Migrator\Services\Migration\MigrationInfoGenerator;
use PanelAlpha\Migrator\Services\Scanner\FileScannerConfig;

/**
 * Class RouteProvider
 *
 * @package PanelAlpha\Migrator\Api
 *
 * Registers API routes for the plugin.
 */
class RouteProvider
{
    private $migrationController;
    private $backupController;
    private $receiveController;
    private $tokenController;
    private $remoteSyncOrchestrationController;

    /**
     * RouteProvider constructor.
     */
    public function __construct()
    {
        $migrationStorageService = new MigrationStorageService();
        $migrationService = new MigrationService(
            new DatabaseScanner(),
            new MigrationInfoGenerator(),
            $migrationStorageService
        );

        $this->migrationController = new MigrationController($migrationService, new HtaccessService());

        $backupService = new BackupService();
        $this->backupController = new BackupController($backupService);

        $this->receiveController = new ReceiveController();

        $this->tokenController = new TokenController();

        $orchestrationService = new RemoteSyncOrchestrationService(
            $migrationService,
            $backupService
        );
        $this->remoteSyncOrchestrationController = new RemoteSyncOrchestrationController($orchestrationService);
    }

    /**
     * Get default exclusion settings.
     *
     * @return array
     */
    public static function getDefaultExclusions(): array
    {
        return [
            'directories' => [
                'node_modules',
                '.git',
                '.svn',
                'wp-content/migrations',
                'wp-content/plugins/panelalpha-migrator',
            ],
            'files' => [
                'wp-config.php',
                '.htaccess',
            ],
            'extensions' => [],
        ];
    }

    /**
     * Create FileScanner with custom exclusions.
     *
     * @param array|null $exclusions Custom exclusions
     * @return FileScanner Configured file scanner instance
     */
    public static function createFileScanner(?array $exclusions = null): FileScanner
    {
        $rootPath = \PanelAlpha\Migrator\Helpers\PathProvider::getAbsPath();
        $defaults = self::getDefaultExclusions();

        $excludedDirectories = array_unique(array_merge(
            $defaults['directories'],
            $exclusions['directories'] ?? []
        ));

        $excludedFiles = array_unique(array_merge(
            $defaults['files'],
            $exclusions['files'] ?? []
        ));

        $excludedExtensions = array_unique(array_merge(
            $defaults['extensions'],
            $exclusions['extensions'] ?? []
        ));

        $fileScannerConfig = new FileScannerConfig(
            $rootPath,
            $excludedDirectories,
            $excludedExtensions,
            $excludedFiles
        );

        return new FileScanner($fileScannerConfig);
    }

    /**
     * Registers routes for the WordPress REST API.
     */
    public function registerWordPressRoutes()
    {
        register_rest_route('panelalpha-migrator/v1', '/scan', [
            'methods' => 'POST',
            'callback' => [$this->migrationController, 'scan'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/info', [
            'methods' => 'GET',
            'callback' => [$this->migrationController, 'info'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/database/next-chunk', [
            'methods' => 'POST',
            'callback' => [$this->backupController, 'databaseNextChunk'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/database/next-chunks-batch', [
            'methods' => 'POST',
            'callback' => [$this->backupController, 'databaseNextChunksBatch'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/database/progress', [
            'methods' => 'GET',
            'callback' => [$this->backupController, 'databaseProgress'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/files/next-chunk', [
            'methods' => 'POST',
            'callback' => [$this->backupController, 'fileNextChunk'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/files/next-chunks-batch', [
            'methods' => 'POST',
            'callback' => [$this->backupController, 'fileNextChunksBatch'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/files/progress', [
            'methods' => 'GET',
            'callback' => [$this->backupController, 'fileProgress'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/status', [
            'methods' => 'GET',
            'callback' => [$this->backupController, 'getStatus'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/start', [
            'methods' => 'POST',
            'callback' => [$this->backupController, 'startBackup'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/backup/stop', [
            'methods' => 'POST',
            'callback' => [$this->backupController, 'stopBackup'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/finalize', [
            'methods' => 'POST',
            'callback' => [$this->migrationController, 'finalize'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/list-migrations', [
            'methods' => 'GET',
            'callback' => [$this->migrationController, 'listMigrations'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/migration-complete', [
            'methods' => 'POST',
            'callback' => [$this->migrationController, 'complete'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/token/current', [
            'methods' => 'GET',
            'callback' => [$this->tokenController, 'getCurrentToken'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/token/generate-and-save', [
            'methods' => 'POST',
            'callback' => [$this->tokenController, 'generateAndSaveToken'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/remote-sync', [
            'methods' => 'POST',
            'callback' => [$this->remoteSyncOrchestrationController, 'start'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/remote-sync/status/(?P<migration_id>[a-zA-Z0-9_]+)', [
            'methods' => 'GET',
            'callback' => [$this->remoteSyncOrchestrationController, 'getStatus'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/remote-sync/process-batch', [
            'methods' => 'POST',
            'callback' => [$this->remoteSyncOrchestrationController, 'processBatch'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        register_rest_route('panelalpha-migrator/v1', '/transfer-htaccess', [
            'methods' => 'POST',
            'callback' => [$this->migrationController, 'transferHtaccess'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);

        // The destination receives a migration here. Only the destination token in the X-Migrator-Token header opens these routes.
        register_rest_route('panelalpha-migrator/v1', '/receive/check', [
            'methods' => 'GET',
            'callback' => [$this->receiveController, 'check'],
            'permission_callback' => [ReceiveController::class, 'authorize'],
        ]);

        register_rest_route('panelalpha-migrator/v1', '/receive', [
            'methods' => 'POST',
            'callback' => [$this->receiveController, 'receive'],
            'permission_callback' => [ReceiveController::class, 'authorize'],
        ]);

        register_rest_route('panelalpha-migrator/v1', '/receive/finalize', [
            'methods' => 'POST',
            'callback' => [$this->receiveController, 'finalize'],
            'permission_callback' => [ReceiveController::class, 'authorize'],
        ]);

        register_rest_route('panelalpha-migrator/v1', '/receive/status', [
            'methods' => 'GET',
            'callback' => [$this->receiveController, 'status'],
            'permission_callback' => [ReceiveController::class, 'authorize'],
        ]);

        register_rest_route('panelalpha-migrator/v1', '/receive-htaccess', [
            'methods' => 'POST',
            'callback' => [$this->migrationController, 'receiveHtaccess'],
            'permission_callback' => function($request) {
                return current_user_can('manage_options') || TokenController::validateToken($request);
            },
        ]);
    }

}
