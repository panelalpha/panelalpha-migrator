<?php

declare(strict_types=1);

namespace WordpressMigrator\Services\Backup;

use WordpressMigrator\Services\Migration\MigrationStorageService;
use WordpressMigrator\Helpers\DateTime;
use WordpressMigrator\Helpers\DestinationGuard;
use WordpressMigrator\Helpers\Http;
use WordpressMigrator\Helpers\PathProvider;
use WordpressMigrator\Helpers\TokenHelper;

/**
 * Class FileBackupService
 *
 * @package WordpressMigrator\Services\Backup
 *
 * Service for handling file backup operations.
 */
class FileBackupService
{
    use DefersMigrationSaves;

    use ProgressCalculator;

    private MigrationStorageService $storageService;
    private ChunkHttpSender $httpSender;
    private string $migrationId;
    private array $migrationData;

    /**
     * FileBackupService constructor.
     *
     * @param string $migrationId Migration identifier
     * @param MigrationStorageService|null $storageService Storage service instance
     * @throws \Exception
     */
    public function __construct(
        string $migrationId,
        ?MigrationStorageService $storageService = null
    ) {
        $this->migrationId = $migrationId;
        $this->storageService = $storageService ?? new MigrationStorageService();

        $this->loadMigrationData();
        $this->initializeDependencies();
    }

    /**
     * Loads migration data from storage.
     *
     * @throws \Exception
     */
    private function loadMigrationData(): void
    {
        if (!$this->storageService->migrationExists($this->migrationId)) {
            throw new \Exception('Migration file not found');
        }

        $this->migrationData = $this->storageService->loadMigrationData($this->migrationId);
    }

    /**
     * Initializes dependencies that require migration data.
     */
    private function initializeDependencies(): void
    {
        $destinationUrl = $this->getDestinationUrl();
        $authToken = $this->getAuthToken();
        $ip = $this->migrationData['pinned_ip'] ?? null;
        $base64 = $this->migrationData['base64'] ?? false;

        $this->httpSender = new ChunkHttpSender($destinationUrl, $authToken, $ip, $base64, Http::tlsArgs($this->migrationData));
    }

    /**
     * Gets the next file chunk to be sent.
     *
     * @return array|null Chunk data or null if all chunks sent
     * @throws \Exception
     */
    public function getNextChunk(): ?array
    {
        foreach ($this->migrationData['files'] as $fileIndex => &$file) {
            foreach ($file['chunks'] as $chunkIndex => &$chunk) {
                if ($chunk['status'] === 'pending') {
                    try {
                        return $this->getFileChunk($fileIndex, $chunkIndex, $file, $chunk);
                    } catch (\Exception $e) {
                        $fullPath = PathProvider::getAbsPath() . $file['path'];
                        if (!file_exists($fullPath)) {
                            $this->skipFile($fileIndex);
                            break;
                        }
                        throw $e;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Marks a file and all its chunks as skipped.
     *
     * @param int $fileIndex File index in migration data
     */
    private function skipFile(int $fileIndex): void
    {
        foreach ($this->migrationData['files'][$fileIndex]['chunks'] as &$chunk) {
            $chunk['status'] = 'skipped';
        }

        $this->migrationData['files'][$fileIndex]['status'] = 'skipped';
        $this->saveMigrationData();
    }

    /**
     * Gets a file chunk.
     *
     * @param int   $fileIndex File index in migration data
     * @param int   $chunkIndex Chunk index in file
     * @param array $file File data array
     * @param array $chunk Chunk data array
     * @return array Simplified chunk response
     * @throws \Exception
     */
    private function getFileChunk(int $fileIndex, int $chunkIndex, array &$file, array &$chunk): array
    {
        $content = $this->readFileChunkContent($file['path'], $chunk['offset'], $chunk['size']);
        $chunkNumber = $chunkIndex + 1;
        $fullChunk = $this->buildFileChunkResponse($file, $chunk, $content, $chunkNumber);

        $this->assertDestinationAllowed();
        $acknowledgement = $this->httpSender->send($fullChunk);

        if ($this->isExplicitSkip($acknowledgement)) {
            $this->markChunkAsSkipped($fileIndex, $chunkIndex, (string) $acknowledgement['result']['reason']);
        } else {
            $this->markChunkAsSent($fileIndex, $chunkIndex);
        }

        $this->updateFileStatus($fileIndex);

        return $this->buildSimplifiedChunkResponse($file, $chunk, $chunkNumber);
    }

    /**
     * Reads the content of a file chunk.
     *
     * @param string $filePath Relative file path
     * @param int    $offset Byte offset
     * @param int    $size Chunk size in bytes
     * @return string File content
     * @throws \Exception
     */
    private function readFileChunkContent(string $filePath, int $offset, int $size): string
    {
        $fullPath = PathProvider::getAbsPath() . $filePath;

        $this->validateFileExists($fullPath, $filePath);
        $handle = $this->openFile($fullPath, $filePath);
        $content = $this->extractChunkContent($handle, $offset, $size, $filePath);

        fclose($handle);

        return $content;

    }

    /**
     * Validates that a file exists.
     *
     * @param string $fullPath Absolute file path
     * @param string $filePath Relative file path for error message
     * @throws \Exception
     */
    private function validateFileExists(string $fullPath, string $filePath): void
    {
        if (!file_exists($fullPath)) {
            throw new \Exception('File not found: ' . $filePath);
        }
    }

    /**
     * Opens a file for reading.
     *
     * @param string $fullPath Absolute file path
     * @param string $filePath Relative file path for error message
     * @return resource File handle
     * @throws \Exception
     */
    private function openFile(string $fullPath, string $filePath)
    {
        $handle = fopen($fullPath, 'rb');

        if (!$handle) {
            throw new \Exception('Cannot open file: ' . $filePath);
        }

        return $handle;
    }

    /**
     * Extracts content from a file chunk.
     *
     * @param resource $handle File handle
     * @param int      $offset Byte offset
     * @param int      $size Chunk size in bytes
     * @param string   $filePath Relative file path for error message
     * @return string File content
     * @throws \Exception
     */
    private function extractChunkContent($handle, int $offset, int $size, string $filePath): string
    {
        if ($size <= 0) {
            return '';
        }

        fseek($handle, $offset);
        $content = fread($handle, $size);

        if ($content === false) {
            throw new \Exception('Cannot read file: ' . $filePath);
        }

        return $content;
    }

    /**
     * Builds the response for a file chunk.
     *
     * @param array  $file File data array
     * @param array  $chunk Chunk data array
     * @param string $content File content
     * @param int    $chunkNumber Sequential chunk number
     * @return array Complete chunk response
     */
    private function buildFileChunkResponse(array &$file, array &$chunk, string $content, int $chunkNumber): array
    {
        return [
            'type' => 'file_chunk',
            'migration_id' => $this->migrationData['migrationId'],
            'chunk_id' => $this->generateChunkId($chunkNumber),
            'file' => [
                'path' => $file['path'],
                'permissions' => $file['permissions'],
                'modified' => $file['modified'],
                'chunk_info' => $this->buildChunkInfo($chunkNumber, $file, $chunk)
            ],
            'content' => base64_encode($content),
            'checksum' => hash('sha256', $content),
            'timestamp' => DateTime::currentTime('c')
        ];
    }

    /**
     * Generates a unique ID for a chunk.
     *
     * @param int $chunkNumber Sequential chunk number
     * @return string Formatted chunk ID
     */
    private function generateChunkId(int $chunkNumber): string
    {
        return 'file_chunk_' . str_pad((string)$chunkNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Builds the chunk information array for the response.
     *
     * @param int   $chunkNumber Sequential chunk number
     * @param array $file File data array
     * @param array $chunk Chunk data array
     * @return array Chunk information
     */
    private function buildChunkInfo(int $chunkNumber, array &$file, array &$chunk): array
    {
        return [
            'chunk_number' => $chunkNumber,
            'total_chunks' => $file['totalChunks'],
            'offset' => $chunk['offset'],
            'size' => $chunk['size']
        ];
    }

    /**
     * Builds a simplified chunk response without content for API response.
     *
     * @param array $file File data array
     * @param array $chunk Chunk data array
     * @param int   $chunkNumber Sequential chunk number
     * @return array Simplified chunk response
     */
    private function buildSimplifiedChunkResponse(array &$file, array &$chunk, int $chunkNumber): array
    {
        return [
            'type' => 'file_chunk',
            'migration_id' => $this->migrationData['migrationId'],
            'chunk_id' => $this->generateChunkId($chunkNumber),
            'file' => [
                'path' => $file['path'],
                'permissions' => $file['permissions'],
                'modified' => $file['modified'],
                'chunk_info' => $this->buildChunkInfo($chunkNumber, $file, $chunk)
            ],
            'timestamp' => DateTime::currentTime('c')
        ];
    }

    /**
     * An explicit exclusion is delivered only as a skip with a reason.
     * A failed or retryable result never reaches this method.
     *
     * @param array $acknowledgement
     * @return bool
     */
    private function isExplicitSkip(array $acknowledgement): bool
    {
        $result = $acknowledgement['result'] ?? null;

        return is_array($result)
            && !empty($result['skipped'])
            && empty($result['can_retry'])
            && !empty($result['reason']);
    }

    /**
     * Marks a chunk as sent.
     *
     * @param int $fileIndex File index
     * @param int $chunkIndex Chunk index
     */
    private function markChunkAsSent(int $fileIndex, int $chunkIndex): void
    {
        $this->migrationData['files'][$fileIndex]['chunks'][$chunkIndex]['status'] = 'sent';
    }

    /**
     * Records an intentional exclusion against one chunk.
     *
     * @param int    $fileIndex File index
     * @param int    $chunkIndex Chunk index
     * @param string $reason Why the destination excluded the file
     */
    private function markChunkAsSkipped(int $fileIndex, int $chunkIndex, string $reason): void
    {
        $this->migrationData['files'][$fileIndex]['chunks'][$chunkIndex]['status'] = 'skipped';
        $this->migrationData['files'][$fileIndex]['chunks'][$chunkIndex]['skip_reason'] = $reason;
    }

    /**
     * Updates the status of a file.
     *
     * @param int $fileIndex File index
     */
    private function updateFileStatus(int $fileIndex): void
    {
        $chunks = $this->migrationData['files'][$fileIndex]['chunks'];
        $pending = false;
        $skipped = 0;
        $sent = 0;

        foreach ($chunks as $chunk) {
            if (($chunk['status'] ?? '') === 'skipped') {
                $skipped++;
            } elseif (($chunk['status'] ?? '') === 'sent') {
                $sent++;
            } else {
                $pending = true;
            }
        }

        if ($pending) {
            $this->migrationData['files'][$fileIndex]['status'] = ($sent > 0 || $skipped > 0) ? 'in_progress' : 'pending';
        } elseif ($sent === 0 && $skipped > 0) {
            $this->migrationData['files'][$fileIndex]['status'] = 'skipped';
        } else {
            $this->migrationData['files'][$fileIndex]['status'] = 'sent';
        }

        $this->saveMigrationData();
    }

    /**
     * Gets the file backup progress.
     *
     * @param string|null $currentFilePath Optional current file path being processed
     * @return array Progress information
     */
    public function getProgress(?string $currentFilePath = null): array
    {
        $stats = $this->calculateProgressStats();
        $percentage = $this->calculatePercentage($stats['total_chunks'], $stats['sent_chunks']);

        return [
            'total_chunks' => $stats['total_chunks'],
            'sent_chunks' => $stats['sent_chunks'],
            'total_files' => $stats['total_files'],
            'completed_files' => $stats['completed_files'],
            'percentage' => $percentage,
            'is_complete' => $stats['unresolved_chunks'] === 0,
            'current_file' => $currentFilePath ?? $stats['current_file'],
            'total_size' => $stats['total_size'],
            'sent_size' => $stats['sent_size']
        ];
    }

    /**
     * Calculates progress statistics.
     *
     * @return array Statistics array with progress information
     */
    private function calculateProgressStats(): array
    {
        $totalChunks = 0;
        $sentChunks = 0;
        $totalFiles = count($this->migrationData['files']);
        $completedFiles = 0;
        $currentFile = null;
        $totalSize = 0;
        $sentSize = 0;
        $unresolvedChunks = 0;

        foreach ($this->migrationData['files'] as $file) {
            $totalChunks += $file['totalChunks'];
            $totalSize += $file['size'];

            if ($file['status'] === 'sent') {
                $completedFiles++;
                $sentSize += $file['size'];
            } elseif ($file['status'] === 'in_progress') {
                $sentSize += $this->calculateSentSize($file['chunks']);

                if ($currentFile === null) {
                    $currentFile = $file['path'];
                }
            } elseif ($file['status'] === 'pending' && $currentFile === null) {
                $currentFile = $file['path'];
            }

            $sentChunks += $this->countSentChunks($file['chunks']);
            $unresolvedChunks += $this->countUnresolvedChunks($file['chunks']);
        }

        return [
            'total_chunks' => $totalChunks,
            'sent_chunks' => $sentChunks,
            'unresolved_chunks' => $unresolvedChunks,
            'total_files' => $totalFiles,
            'completed_files' => $completedFiles,
            'current_file' => $currentFile,
            'total_size' => $totalSize,
            'sent_size' => $sentSize
        ];
    }

    /**
     * Counts chunks that are neither delivered nor explicitly skipped.
     *
     * @param array $chunks
     * @return int
     */
    private function countUnresolvedChunks(array $chunks): int
    {
        $count = 0;

        foreach ($chunks as $chunk) {
            $status = $chunk['status'] ?? '';
            if ($status !== 'sent' && $status !== 'skipped') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Refuses a stored destination that points at a private or loopback address.
     * Runs once for a migration without a pinned IP, then keeps the approved IP.
     */
    private function assertDestinationAllowed(): void
    {
        $pinned = $this->migrationData['pinned_ip'] ?? null;
        if (is_string($pinned) && $pinned !== '') {
            return;
        }

        $ip = $this->migrationData['ip'] ?? null;
        $pinned = (new DestinationGuard())->assertAllowed(
            $this->getDestinationUrl(),
            is_string($ip) && $ip !== '' ? $ip : null
        );

        if ($pinned === null) {
            return;
        }

        $this->migrationData['pinned_ip'] = $pinned;
        $this->saveMigrationData();
        $this->httpSender->setIp($pinned);
    }

    /**
     * Gets the destination URL from migration data.
     *
     * @return string Destination URL
     * @throws \Exception If destination URL not found
     */
    private function getDestinationUrl(): string
    {
        $destinationUrl = $this->migrationData['destinationUrl']
            ?? $this->migrationData['destination_url']
            ?? $this->migrationData['du']
            ?? null;

        if (empty($destinationUrl)) {
            throw new \Exception('Destination URL not found in migration data');
        }

        return $destinationUrl;
    }

    /**
     * Gets the authentication token.
     *
     * @return string Authentication token
     */
    private function getAuthToken(): string
    {
        return TokenHelper::forMigration($this->migrationData);
    }
}
