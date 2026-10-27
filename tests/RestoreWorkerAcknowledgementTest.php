<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\RestoreWorker;

final class RestoreWorkerAcknowledgementTest extends TestCase
{
    public function testFailedWriteIsNotASuccessfulAcknowledgement(): void
    {
        $ack = RestoreWorker::acknowledgement('mig_1', [
            'type' => 'file_chunk',
            'chunk_id' => 'file_chunk_00001',
        ], [
            'failed' => true,
            'status' => 'pending',
            'reason' => 'Failed to write file (disk full or permissions issue)',
            'can_retry' => true,
        ]);

        $this->assertFalse($ack['success']);
        $this->assertFalse($ack['processed']);
        $this->assertSame('mig_1', $ack['migration_id']);
        $this->assertSame('file_chunk_00001', $ack['chunk_id']);
        $this->assertSame('pending', $ack['result']['status']);
    }

    public function testExplicitSkipKeepsItsReason(): void
    {
        $ack = RestoreWorker::acknowledgement('mig_1', [
            'type' => 'file_chunk',
            'chunk_id' => 'file_chunk_00002',
        ], [
            'skipped' => true,
            'reason' => 'Critical file excluded from restore',
            'can_retry' => false,
        ]);

        $this->assertTrue($ack['success']);
        $this->assertSame('mig_1', $ack['migration_id']);
        $this->assertSame('file_chunk_00002', $ack['chunk_id']);
        $this->assertSame('Critical file excluded from restore', $ack['result']['reason']);
    }
}
