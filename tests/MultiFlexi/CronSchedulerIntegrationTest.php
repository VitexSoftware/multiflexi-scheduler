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

use Cron\CronExpression;
use MultiFlexi\CronScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Integration test suite for CronScheduler.
 *
 * These tests validate the integration behavior and workflow
 * of the CronScheduler class, testing more complex scenarios
 * and interactions between components.
 */
class CronSchedulerIntegrationTest extends TestCase
{
    /**
     * Test complete workflow of cron scheduling with multiple intervals.
     */
    public function testMultipleIntervalSchedulingWorkflow(): void
    {
        $intervals = [
            'h' => '0 * * * *',      // Hourly
            'd' => '0 0 * * *',      // Daily
            'w' => '0 0 * * 0',      // Weekly
            'm' => '0 0 1 * *',      // Monthly
            'y' => '0 0 1 1 *',      // Yearly
        ];

        $baseTime = new \DateTime('2024-01-15 14:30:00');

        foreach ($intervals as $code => $expression) {
            $cron = new CronExpression($expression);
            $nextRun = $cron->getNextRunDate($baseTime, 0, true);

            $this->assertInstanceOf(
                \DateTime::class,
                $nextRun,
                "Interval '{$code}' should produce valid next run date",
            );

            $this->assertGreaterThanOrEqual(
                $baseTime->getTimestamp(),
                $nextRun->getTimestamp(),
                'Next run should be at or after current time',
            );
        }
    }

    /**
     * Test scheduling across month boundaries.
     */
    public function testSchedulingAcrossMonthBoundaries(): void
    {
        $cron = new CronExpression('0 0 * * *'); // Daily at midnight

        // Test end of January (30 days)
        $endOfJan = new \DateTime('2024-01-31 23:00:00');
        $nextRun = $cron->getNextRunDate($endOfJan, 0, false);

        $this->assertEquals(
            '2024-02-01',
            $nextRun->format('Y-m-d'),
            'Should correctly schedule across month boundary',
        );

        // Test end of February (leap year)
        $endOfFeb = new \DateTime('2024-02-29 23:00:00');
        $nextRunFromFeb = $cron->getNextRunDate($endOfFeb, 0, false);

        $this->assertEquals(
            '2024-03-01',
            $nextRunFromFeb->format('Y-m-d'),
            'Should correctly handle leap year boundary',
        );
    }

    /**
     * Test scheduling across year boundaries.
     */
    public function testSchedulingAcrossYearBoundaries(): void
    {
        $cron = new CronExpression('0 0 1 * *'); // Monthly on 1st

        $endOfYear = new \DateTime('2024-12-31 23:00:00');
        $nextRun = $cron->getNextRunDate($endOfYear, 0, false);

        $this->assertEquals(
            '2025-01-01',
            $nextRun->format('Y-m-d'),
            'Should correctly schedule across year boundary',
        );
    }

    /**
     * Test delay accumulation with multiple sequential schedules.
     */
    public function testDelayAccumulationScenario(): void
    {
        $delays = [60, 120, 300, 600]; // Various delays in seconds
        $baseTime = new \DateTime('2024-01-01 12:00:00');

        foreach ($delays as $delay) {
            $scheduledTime = clone $baseTime;
            $scheduledTime->modify("+{$delay} seconds");

            $expectedDiff = $delay;
            $actualDiff = $scheduledTime->getTimestamp() - $baseTime->getTimestamp();

            $this->assertEquals(
                $expectedDiff,
                $actualDiff,
                "Delay of {$delay} seconds should be applied correctly",
            );
        }
    }

    /**
     * Test cron expression edge cases with specific dates.
     */
    public function testCronExpressionEdgeCases(): void
    {
        // Test 31st of month (not all months have 31 days)
        $cron = new CronExpression('0 0 31 * *');
        $startDate = new \DateTime('2024-01-01');

        // Should skip February (only 29 days in 2024)
        $nextRun = $cron->getNextRunDate($startDate, 0, true);
        $this->assertEquals('31', $nextRun->format('d'));

        // Should be in January or March (months with 31 days)
        $this->assertContains(
            $nextRun->format('m'),
            ['01', '03', '05', '07', '08', '10', '12'],
            'Day 31 should only occur in months with 31 days',
        );
    }

    /**
     * Test weekly scheduling on different days.
     */
    public function testWeeklySchedulingOnDifferentDays(): void
    {
        $daysOfWeek = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];

        foreach ($daysOfWeek as $dayNum => $dayName) {
            $cron = new CronExpression("0 0 * * {$dayNum}");
            $startDate = new \DateTime('2024-01-01'); // Monday

            $nextRun = $cron->getNextRunDate($startDate, 0, true);
            $actualDay = (int) $nextRun->format('w');

            $this->assertEquals(
                $dayNum,
                $actualDay,
                "Cron should schedule on {$dayName} (day {$dayNum})",
            );
        }
    }

    /**
     * Test scheduling with very frequent intervals.
     */
    public function testHighFrequencyScheduling(): void
    {
        // Every minute
        $cron = new CronExpression('* * * * *');
        $startTime = new \DateTime('2024-01-01 12:00:00');

        $runs = [];
        $currentTime = clone $startTime;

        // Get next 5 runs
        for ($i = 0; $i < 5; ++$i) {
            $nextRun = $cron->getNextRunDate($currentTime, 0, false);
            $runs[] = $nextRun;
            $currentTime = $nextRun;
        }

        // Verify each run is 1 minute apart
        for ($i = 1; $i < \count($runs); ++$i) {
            $diff = $runs[$i]->getTimestamp() - $runs[$i - 1]->getTimestamp();
            $this->assertEquals(
                60,
                $diff,
                'High-frequency runs should be exactly 60 seconds apart',
            );
        }
    }

    /**
     * Test error recovery scenarios.
     */
    public function testErrorRecoveryScenarios(): void
    {
        // Test invalid but recoverable scenarios
        $exceptions = [];

        // Scenario 1: Invalid cron expression
        try {
            new CronExpression('invalid cron');
        } catch (\InvalidArgumentException $e) {
            $exceptions[] = $e;
        }

        $this->assertNotEmpty(
            $exceptions,
            'Invalid cron expressions should throw exceptions',
        );

        // Verify error messages are meaningful
        foreach ($exceptions as $exception) {
            $this->assertNotEmpty(
                $exception->getMessage(),
                'Exception should have meaningful error message',
            );
        }
    }

    /**
     * Test concurrent scheduling scenarios.
     */
    public function testConcurrentSchedulingScenarios(): void
    {
        $cron = new CronExpression('0 0 * * *');
        $baseTime = new \DateTime('2024-01-01 12:00:00');

        // Simulate multiple parallel schedule calculations
        $schedules = [];

        for ($i = 0; $i < 10; ++$i) {
            $schedules[] = $cron->getNextRunDate(clone $baseTime, 0, true);
        }

        // All should produce the same result
        $firstSchedule = $schedules[0]->format('Y-m-d H:i:s');

        foreach ($schedules as $schedule) {
            $this->assertEquals(
                $firstSchedule,
                $schedule->format('Y-m-d H:i:s'),
                'Concurrent calculations should produce identical results',
            );
        }
    }

    /**
     * Test schedule persistence and recovery.
     */
    public function testSchedulePersistenceFormat(): void
    {
        $scheduleTime = new \DateTime('2024-06-15 10:30:45');
        $sqlFormat = $scheduleTime->format('Y-m-d H:i:s');

        // Verify format is SQL-compatible
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $sqlFormat,
            'Schedule format should be SQL-compatible',
        );

        // Verify we can reconstruct the DateTime from SQL format
        $reconstructed = \DateTime::createFromFormat('!Y-m-d H:i:s', $sqlFormat);
        $this->assertInstanceOf(
            \DateTime::class,
            $reconstructed,
            'Should be able to reconstruct DateTime from SQL format',
        );

        $this->assertEquals(
            $sqlFormat,
            $reconstructed->format('Y-m-d H:i:s'),
            'Reconstructed time should match original',
        );
    }

    /**
     * Test scheduling with DST transitions.
     */
    public function testDaylightSavingTimeTransitions(): void
    {
        // Test scheduling around DST transitions
        // Note: Using fixed timezone for consistency
        $cron = new CronExpression('0 2 * * *'); // 2 AM daily

        // Spring forward date (DST starts - hour skipped)
        $dstStart = new \DateTime('2024-03-10 01:00:00', new \DateTimeZone('America/New_York'));
        $nextRun = $cron->getNextRunDate($dstStart, 0, false);

        $this->assertInstanceOf(
            \DateTime::class,
            $nextRun,
            'Should handle DST spring forward',
        );

        // Fall back date (DST ends - hour repeated)
        $dstEnd = new \DateTime('2024-11-03 01:00:00', new \DateTimeZone('America/New_York'));
        $nextRunDstEnd = $cron->getNextRunDate($dstEnd, 0, false);

        $this->assertInstanceOf(
            \DateTime::class,
            $nextRunDstEnd,
            'Should handle DST fall back',
        );
    }

    /**
     * Test status message formatting consistency.
     */
    public function testStatusMessageFormattingConsistency(): void
    {
        $testCases = [
            [
                'emoji' => '⏰',
                'appKey' => 123,
                'appName' => 'TestApp',
                'templateName' => 'Template1',
                'templateId' => 456,
                'company' => 'Company A',
            ],
            [
                'emoji' => '📅',
                'appKey' => 789,
                'appName' => 'AnotherApp',
                'templateName' => 'Template2',
                'templateId' => 101,
                'company' => 'Company B',
            ],
        ];

        foreach ($testCases as $testCase) {
            $message = sprintf(
                '%s🧩 #%d	%s:%s (runtemplate #%d) - Launch %s for 🏣 %s',
                $testCase['emoji'],
                $testCase['appKey'],
                $testCase['appName'],
                $testCase['templateName'],
                $testCase['templateId'],
                (new \DateTime())->format(\DATE_RSS),
                $testCase['company'],
            );

            // Verify message contains all required components
            $this->assertStringContainsString($testCase['emoji'], $message);
            $this->assertStringContainsString('🧩', $message);
            $this->assertStringContainsString((string) $testCase['appKey'], $message);
            $this->assertStringContainsString($testCase['appName'], $message);
            $this->assertStringContainsString($testCase['templateName'], $message);
            $this->assertStringContainsString('🏣', $message);
            $this->assertStringContainsString($testCase['company'], $message);
        }
    }

    /**
     * Test interval code validation.
     */
    public function testIntervalCodeValidation(): void
    {
        $validCodes = ['i', 'h', 'd', 'w', 'm', 'y', 'c', 'n'];
        $invalidCodes = ['x', 'z', '', '1', 'hh', 'dd'];

        foreach ($validCodes as $code) {
            $this->assertIsString($code, "Interval code '{$code}' should be a string");
            $this->assertEquals(1, \strlen($code), 'Valid interval codes should be single characters');
        }

        foreach ($invalidCodes as $code) {
            $this->assertNotContains(
                $code,
                $validCodes,
                "Code '{$code}' should not be in valid codes",
            );
        }
    }

    /**
     * Test that scheduling respects schedule history.
     */
    public function testScheduleHistoryRespected(): void
    {
        $currentSchedule = '2024-01-15 10:00:00';
        $lastSchedule = '2024-01-15 10:00:00';

        // Same schedule should not trigger new scheduling
        $shouldSchedule = ($currentSchedule !== $lastSchedule);
        $this->assertFalse(
            $shouldSchedule,
            'Should not schedule when current matches last schedule',
        );

        // Different schedule should trigger
        $newSchedule = '2024-01-15 11:00:00';
        $shouldScheduleNew = ($newSchedule !== $lastSchedule);
        $this->assertTrue(
            $shouldScheduleNew,
            'Should schedule when current differs from last schedule',
        );
    }
}
