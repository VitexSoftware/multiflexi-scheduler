<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi\Test;

use MultiFlexi\CronScheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the scheduler daemon memory leak.
 *
 * The daemon died with "Allowed memory size exhausted" in Ease\Logger\ToStd
 * because every logged message stayed in a static array forever.
 *
 * All tests are pure / offline — no database required.
 */
class LogBufferTest extends TestCase
{
    private function schedulerWithoutDatabase(): CronScheduler
    {
        return (new \ReflectionClass(CronScheduler::class))->newInstanceWithoutConstructor();
    }

    public function testReleaseLogBuffersEmptiesStaticMessageStores(): void
    {
        \Ease\Logger\ToMemory::$statusMessages = ['warning' => array_fill(0, 1000, 'Skipping duplicate job creation')];
        \Ease\Shared::$statusMessages = ['info' => ['x']];

        $this->schedulerWithoutDatabase()->releaseLogBuffers();

        $this->assertSame([], \Ease\Logger\ToMemory::$statusMessages);
        $this->assertSame([], \Ease\Shared::$statusMessages);
    }

    public function testToStdGrowthIsBoundedWhenBuffersAreReleased(): void
    {
        $scheduler = $this->schedulerWithoutDatabase();
        $logger = new \Ease\Logger\ToStd('leak-test');
        $peak = 0;

        ob_start();

        try {
            for ($tick = 0; $tick < 20; ++$tick) {
                for ($i = 0; $i < 500; ++$i) {
                    $logger->addToLog($scheduler, 'Skipping duplicate job creation for runtemplate #'.$i, 'warning');
                }

                $peak = max($peak, array_sum(array_map('count', \Ease\Logger\ToMemory::$statusMessages)));
                $scheduler->releaseLogBuffers();
            }
        } finally {
            ob_end_clean();
        }

        $this->assertSame(500, $peak, 'Only one tick worth of messages may be held at a time');
        $this->assertSame([], \Ease\Logger\ToMemory::$statusMessages);
    }

    #[DataProvider('memoryLimitProvider')]
    public function testMemoryLimitBytes(string $ini, int $expected): void
    {
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', $ini);

        try {
            $this->assertSame($expected, CronScheduler::memoryLimitBytes());
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function memoryLimitProvider(): array
    {
        return [
            'unlimited' => ['-1', 0],
            'megabytes' => ['256M', 268435456],
            'gigabytes' => ['1G', 1073741824],
            'kilobytes' => ['65536K', 67108864],
            'plain bytes' => ['134217728', 134217728],
        ];
    }

    public function testMemoryExceeded(): void
    {
        $limit = 256 * 1024 * 1024;

        $this->assertFalse(CronScheduler::memoryExceeded(100 * 1024 * 1024, $limit));
        $this->assertTrue(CronScheduler::memoryExceeded(192 * 1024 * 1024, $limit), '75 % of the limit triggers a restart');
        $this->assertFalse(CronScheduler::memoryExceeded(\PHP_INT_MAX, 0), 'Unlimited memory never triggers a restart');
    }

    public function testDaemonReleasesBuffersAndShutsDownGracefully(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/src/daemon.php');
        $this->assertStringContainsString('releaseLogBuffers()', $source);
        $this->assertStringContainsString('memoryExceeded(', $source);
        $this->assertStringContainsString('pcntl_signal(\SIGTERM', $source, 'SIGTERM must request a graceful shutdown');
        $this->assertStringContainsString('while ($daemonize && !$shutdown)', $source, 'The loop must end after the current tick once shutdown is requested');
    }

    public function testSystemdUnitRestartsAfterCleanExit(): void
    {
        $unit = file_get_contents(\dirname(__DIR__, 2).'/debian/multiflexi-scheduler.service');
        $this->assertStringContainsString('Restart=always', $unit);
        $this->assertStringContainsString('TimeoutStopSec=', $unit);
    }
}
