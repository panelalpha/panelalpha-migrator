<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Services\Backup\ProgressCalculator;

final class ProgressCalculatorTest extends TestCase
{
    private object $calculator;

    protected function setUp(): void
    {
        $this->calculator = new class {
            use ProgressCalculator {
                calculatePercentage as public;
                countSentChunks as public;
                areAllChunksSent as public;
                calculateSentSize as public;
                findCurrentItem as public;
            }
        };
    }

    public function testPercentageIsRoundedToTwoDecimals(): void
    {
        $this->assertSame(33.33, $this->calculator->calculatePercentage(3, 1));
        $this->assertSame(100.0, $this->calculator->calculatePercentage(4, 4));
    }

    public function testPercentageOfNothingIsZero(): void
    {
        $this->assertEquals(0, $this->calculator->calculatePercentage(0, 0));
    }

    public function testOnlySentChunksAreCounted(): void
    {
        $chunks = [
            ['status' => 'sent'],
            ['status' => 'pending'],
            ['status' => 'sent'],
            ['status' => 'skipped'],
        ];

        $this->assertSame(2, $this->calculator->countSentChunks($chunks));
    }

    public function testAllChunksSentOnlyWhenEveryChunkIsSent(): void
    {
        $this->assertTrue($this->calculator->areAllChunksSent([['status' => 'sent'], ['status' => 'sent']]));
        $this->assertFalse($this->calculator->areAllChunksSent([['status' => 'sent'], ['status' => 'pending']]));
        $this->assertTrue($this->calculator->areAllChunksSent([]));
    }

    public function testSentSizeAddsSentChunksAndTreatsMissingSizeAsZero(): void
    {
        $chunks = [
            ['status' => 'sent', 'size' => 100],
            ['status' => 'pending', 'size' => 1000],
            ['status' => 'sent'],
            ['status' => 'sent', 'size' => 20],
        ];

        $this->assertSame(120, $this->calculator->calculateSentSize($chunks));
    }

    public function testItemInProgressWinsOverAnEarlierPendingItem(): void
    {
        $items = [
            ['status' => 'sent', 'name' => 'wp_options'],
            ['status' => 'pending', 'name' => 'wp_posts'],
            ['status' => 'in_progress', 'name' => 'wp_users'],
        ];

        $this->assertSame('wp_users', $this->calculator->findCurrentItem($items));
    }

    public function testFirstPendingItemIsCurrentWhenNothingIsInProgress(): void
    {
        $items = [
            ['status' => 'sent', 'path' => 'index.php'],
            ['status' => 'pending', 'path' => 'wp-login.php'],
            ['status' => 'pending', 'path' => 'wp-cron.php'],
        ];

        $this->assertSame('wp-login.php', $this->calculator->findCurrentItem($items));
    }

    public function testNoCurrentItemWhenEverythingIsSent(): void
    {
        $this->assertNull($this->calculator->findCurrentItem([['status' => 'sent', 'name' => 'wp_posts']]));
    }
}
