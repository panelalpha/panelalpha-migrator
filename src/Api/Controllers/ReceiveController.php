<?php

namespace PanelAlpha\Migrator\Api\Controllers;

use PanelAlpha\Migrator\Api\Middleware\InvalidRequestException;
use PanelAlpha\Migrator\Api\Middleware\RequestValidator;
use PanelAlpha\Migrator\Api\Middleware\ResponseFormatter;
use PanelAlpha\Migrator\Helpers\Config;
use PanelAlpha\Migrator\Helpers\PathProvider;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Restore\ChunkProcessor;
use PanelAlpha\Migrator\Restore\DatabasePrivilegesException;
use PanelAlpha\Migrator\Restore\FileRestoreHandler;
use PanelAlpha\Migrator\Restore\InsufficientDiskSpaceException;
use PanelAlpha\Migrator\Restore\MigrationConflictException;
use PanelAlpha\Migrator\Restore\MigrationNotFoundException;
use PanelAlpha\Migrator\Restore\ReceiveFinalizer;
use PanelAlpha\Migrator\Restore\ReceiveStarter;
use PanelAlpha\Migrator\Restore\TableRestoreHandler;
use PanelAlpha\Migrator\Services\Backup\ChunkService;
use PanelAlpha\Migrator\Services\Migration\MigrationRegistry;

/**
 * Class ReceiveController
 *
 * @package PanelAlpha\Migrator\Api\Controllers
 *
 * Receives a migration on the destination: the chunks from the source and the finalize call.
 * The routes accept only the destination token, sent in the X-Migrator-Token header.
 */
class ReceiveController
{
    private const RECEIVE_VERSION = 2;
    private const CHUNK_TYPES = ['info', 'table_structure', 'table_data', 'file_chunk'];

    private $requestValidator;
    private $responseFormatter;
    private $wpdb;
    private $abspath;

    /**
     * ReceiveController constructor.
     *
     * @param \wpdb|null             $wpdb      The global $wpdb when not given
     * @param string|null            $abspath   ABSPATH when not given
     * @param RequestValidator|null  $requestValidator
     * @param ResponseFormatter|null $responseFormatter
     */
    public function __construct(
        ?object $wpdb = null,
        ?string $abspath = null,
        ?RequestValidator $requestValidator = null,
        ?ResponseFormatter $responseFormatter = null
    ) {
        $this->wpdb = $wpdb;
        $this->abspath = $abspath;
        $this->requestValidator = $requestValidator ?? new RequestValidator();
        $this->responseFormatter = $responseFormatter ?? new ResponseFormatter();
    }

    /**
     * Permission check of the receive routes. Only the header token counts, a token in the address
     * would end up in the server logs.
     *
     * @param mixed $request
     * @return true|\WP_Error
     */
    public static function authorize($request)
    {
        $configured = TokenHelper::getToken();

        if ($configured === null) {
            return new \WP_Error('rest_forbidden', 'Destination token is not configured', ['status' => 401]);
        }

        $given = is_object($request) && method_exists($request, 'get_header') ? $request->get_header('x_migrator_token') : null;

        if (is_string($given) && $given !== '' && hash_equals($configured, $given)) {
            return true;
        }

        return new \WP_Error('rest_forbidden', 'Missing or invalid migrator token', ['status' => 401]);
    }

    /**
     * Tells the source that this destination can receive a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function check($request)
    {
        return $this->noStore($this->responseFormatter->success([
            'success' => true,
            'receive' => self::RECEIVE_VERSION,
            'version' => PathProvider::getVersion(),
            'wordpress_version' => Config::get('wp_version', 'unknown'),
            'free_disk_space' => $this->starter()->freeDiskSpace(),
        ]));
    }

    /**
     * Receives one chunk: the info chunk, a table structure or data chunk, or a file chunk.
     *
     * @param mixed $request
     * @return mixed
     */
    public function receive($request)
    {
        $this->raiseMemoryLimit();

        try {
            $chunkData = $this->requestValidator->getChunkBody($request);
            $migrationId = $this->chunkMigrationId($chunkData);
            $type = $chunkData['type'] ?? null;

            if (!in_array($type, self::CHUNK_TYPES, true)) {
                throw new InvalidRequestException('invalid_chunk', 'Invalid chunk data');
            }

            $registry = new MigrationRegistry($migrationId);

            if ($type === 'info') {
                $this->assertValidInfo($chunkData);
            } else {
                $this->assertAcceptsChunks($registry);
            }

            $result = $registry->withStatusLock(function () use ($type, $migrationId, $chunkData, $registry) {
                if ($type === 'info') {
                    return $this->starter()->start($migrationId, $chunkData);
                }

                return $this->processor($registry)->process($migrationId, $chunkData);
            });

            return $this->responseFormatter->success(self::acknowledgement($migrationId, $chunkData, $result));
        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (MigrationConflictException $e) {
            return $this->responseFormatter->error('migration_conflict', $e->getMessage(), ['status' => 409]);
        } catch (DatabasePrivilegesException $e) {
            return $this->responseFormatter->error('destination_db_privileges', $e->getMessage(), ['status' => 400]);
        } catch (InsufficientDiskSpaceException $e) {
            return $this->responseFormatter->error('insufficient_disk_space', $e->getMessage(), ['status' => 400]);
        } catch (\Throwable $e) {
            error_log('PanelAlpha Migrator: Processing failed: ' . $e->getMessage());

            return $this->responseFormatter->error(
                'processing_failed',
                'Processing failed. Check the PHP error log on the destination.',
                ['status' => 500]
            );
        }
    }

    /**
     * Finalizes the migration. While the destination is still finishing, the answer is 202 with finalized false.
     *
     * @param mixed $request
     * @return mixed
     */
    public function finalize($request)
    {
        $this->raiseMemoryLimit();

        try {
            $chunkData = $this->requestValidator->getChunkBody($request);
            $migrationId = $this->chunkMigrationId($chunkData);
            $outcome = (new ReceiveFinalizer($this->wpdb(), $this->abspath()))->finalize(
                $migrationId,
                is_array($chunkData['skipped_files'] ?? null) ? $chunkData['skipped_files'] : []
            );

            if (!$outcome['finalized']) {
                $response = $this->responseFormatter->success([
                    'success' => true,
                    'finalized' => false,
                    'phase' => $outcome['phase'],
                    'migration_id' => $migrationId,
                ]);
                $response->set_status(202);

                return $response;
            }

            $result = $outcome['result'];
            $response = [
                'success' => true,
                'finalized' => true,
                'migration_id' => $migrationId,
                'chunk_type' => 'migration_complete',
                'processed' => true,
                'result' => $result,
                'timestamp' => gmdate('c'),
            ];

            foreach (['skipped_files', 'warning', 'warnings'] as $key) {
                if (isset($result[$key])) {
                    $response[$key] = $result[$key];
                }
            }

            return $this->responseFormatter->success($response);
        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (MigrationNotFoundException $e) {
            return $this->responseFormatter->error('not_found', $e->getMessage(), ['status' => 404]);
        } catch (MigrationConflictException $e) {
            return $this->responseFormatter->error('migration_conflict', $e->getMessage(), ['status' => 409]);
        } catch (\Throwable $e) {
            return $this->responseFormatter->error('finalize_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Reports the state of a migration.
     *
     * @param mixed $request
     * @return mixed
     */
    public function status($request)
    {
        try {
            $migrationId = $this->requestValidator->getMigrationId($request);

            if (!PathProvider::isSafeMigrationId($migrationId)) {
                throw new InvalidRequestException('invalid_id', 'Invalid migration ID');
            }

            $status = (new MigrationRegistry($migrationId))->getStatus();

            if (!$status) {
                return $this->responseFormatter->error('not_found', 'Migration not found', ['status' => 404]);
            }

            $response = [
                'success' => true,
                'migration_id' => $migrationId,
                'status' => $status,
            ];

            $finalize = ReceiveFinalizer::readJournal($migrationId);

            if ($finalize['state'] !== null) {
                $response['finalize'] = $finalize['state'];
            }

            return $this->noStore($this->responseFormatter->success($response));
        } catch (InvalidRequestException $e) {
            return $this->responseFormatter->error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (\Throwable $e) {
            error_log('PanelAlpha Migrator: Status check failed: ' . $e->getMessage());

            return $this->responseFormatter->error(
                'status_failed',
                'Status check failed. Check the PHP error log on the destination.',
                ['status' => 500]
            );
        }
    }

    /**
     * Builds the acknowledgement returned to the source.
     * A failed or retryable result is not a successful delivery.
     * An explicit skip keeps its reason and is acknowledged as such.
     *
     * @param string $migrationId
     * @param array  $chunkData
     * @param array  $result
     * @return array
     */
    public static function acknowledgement(string $migrationId, array $chunkData, array $result): array
    {
        $delivered = empty($result['failed']) && empty($result['can_retry']);

        return [
            'success' => $delivered,
            'migration_id' => $migrationId,
            'chunk_type' => $chunkData['type'] ?? null,
            'chunk_id' => $chunkData['chunk_id'] ?? null,
            'processed' => $delivered,
            'result' => $result,
            'timestamp' => gmdate('c')
        ];
    }

    /**
     * Reads and checks the migration id of a chunk. The ids end up in paths.
     *
     * @param array $chunkData
     * @return string
     * @throws InvalidRequestException
     */
    private function chunkMigrationId(array $chunkData): string
    {
        $migrationId = $chunkData['migration_id'] ?? null;

        if (!is_string($migrationId) || !PathProvider::isSafeMigrationId($migrationId)) {
            throw new InvalidRequestException('invalid_id', 'Invalid migration ID');
        }

        $chunkId = $chunkData['chunk_id'] ?? null;

        if ($chunkId !== null && (!is_string($chunkId) || !PathProvider::isSafeChunkToken($chunkId))) {
            throw new InvalidRequestException('invalid_id', 'Invalid chunk ID');
        }

        return $migrationId;
    }

    /**
     * Checks that the info chunk has the manifest the destination needs to register the migration.
     *
     * @param array $info
     * @throws InvalidRequestException
     */
    private function assertValidInfo(array $info): void
    {
        foreach (['tables' => 'name', 'files' => 'path'] as $list => $key) {
            $items = $info[$list] ?? [];

            if (!is_array($items)) {
                throw new InvalidRequestException('invalid_chunk', 'Invalid chunk data');
            }

            foreach ($items as $item) {
                if (!is_array($item) || !isset($item[$key]) || !is_string($item[$key]) || !isset($item['total_chunks']) || !is_numeric($item['total_chunks'])) {
                    throw new InvalidRequestException('invalid_chunk', 'Invalid chunk data');
                }
            }
        }
    }

    /**
     * Data chunks belong to a migration that the info chunk registered and that is still running.
     *
     * @param MigrationRegistry $registry
     * @throws InvalidRequestException
     * @throws MigrationConflictException
     */
    private function assertAcceptsChunks(MigrationRegistry $registry): void
    {
        $status = $registry->getStatus();

        if (!$status) {
            throw new MigrationConflictException('Unknown migration. Send the info chunk first.');
        }

        if (($status['status'] ?? '') === 'abandoned') {
            throw new MigrationConflictException('This migration was replaced by a newer one');
        }

        if (($status['status'] ?? '') === 'restored') {
            throw new MigrationConflictException('This migration is finished');
        }
    }

    /**
     * WordPress and the active plugins are loaded for every chunk, so the memory limit is raised the way wp-admin does it.
     */
    private function raiseMemoryLimit(): void
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('panelalpha_migrator');
        }
    }

    /**
     * Keeps a cache between the source and the destination from answering a GET with an old state.
     *
     * @param mixed $response
     * @return mixed
     */
    private function noStore($response)
    {
        if (is_object($response) && method_exists($response, 'header')) {
            $response->header('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function processor(MigrationRegistry $registry): ChunkProcessor
    {
        return new ChunkProcessor(
            new TableRestoreHandler($this->wpdb(), $registry),
            new FileRestoreHandler($registry, $this->abspath()),
            new ChunkService()
        );
    }

    private function starter(): ReceiveStarter
    {
        return new ReceiveStarter($this->wpdb());
    }

    private function wpdb(): object
    {
        return $this->wpdb ?? $GLOBALS['wpdb'];
    }

    private function abspath(): string
    {
        return $this->abspath ?? (defined('ABSPATH') ? ABSPATH : getcwd() . '/');
    }
}
