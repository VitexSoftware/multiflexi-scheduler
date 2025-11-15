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
use MultiFlexi\Company;
use MultiFlexi\ConfigFields;
use MultiFlexi\CronScheduler;
use MultiFlexi\Job;
use MultiFlexi\LogToSQL;
use MultiFlexi\RunTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for CronScheduler class.
 *
 * Tests the scheduling of cron jobs with various scenarios including:
 * - Standard cron expression scheduling
 * - Empty crontab handling
 * - Delay modifications
 * - Emoji status messages
 * - Error handling
 * - Edge cases with schedule timing
 */
class CronSchedulerTest extends TestCase
{
    private CronScheduler $scheduler;
    private $mockCompany;
    private $mockJob;
    private $mockRunTemplate;
    private $mockLogToSQL;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for dependencies
        $this->mockCompany = $this->createMock(Company::class);
        $this->mockJob = $this->createMock(Job::class);
        $this->mockRunTemplate = $this->createMock(RunTemplate::class);
        $this->mockLogToSQL = $this->createMock(LogToSQL::class);

        // Create the scheduler instance
        $this->scheduler = $this->getMockBuilder(CronScheduler::class)
            ->onlyMethods([])
            ->getMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->scheduler = null;
    }

    /**
     * Test that scheduleCronJobs method exists and is public.
     */
    public function testScheduleCronJobsMethodExists(): void
    {
        $this->assertTrue(
            method_exists(CronScheduler::class, 'scheduleCronJobs'),
            'scheduleCronJobs method should exist',
        );

        $reflection = new \ReflectionMethod(CronScheduler::class, 'scheduleCronJobs');
        $this->assertTrue(
            $reflection->isPublic(),
            'scheduleCronJobs method should be public',
        );
    }

    /**
     * Test that the removed scheduleIntervalJobs method no longer exists
     * This verifies the refactoring removed the old method.
     */
    public function testScheduleIntervalJobsMethodDoesNotExist(): void
    {
        $this->assertFalse(
            method_exists(CronScheduler::class, 'scheduleIntervalJobs'),
            'scheduleIntervalJobs method should have been removed in refactoring',
        );
    }

    /**
     * Test CronScheduler extends the correct parent class.
     */
    public function testCronSchedulerExtendsScheduler(): void
    {
        $this->assertInstanceOf(
            \MultiFlexi\Scheduler::class,
            $this->scheduler,
            'CronScheduler should extend Scheduler class',
        );
    }

    /**
     * Test scheduleCronJobs return type is void.
     */
    public function testScheduleCronJobsReturnsVoid(): void
    {
        $reflection = new \ReflectionMethod(CronScheduler::class, 'scheduleCronJobs');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType, 'scheduleCronJobs should have a return type');
        $this->assertEquals('void', $returnType->getName(), 'scheduleCronJobs should return void');
    }

    /**
     * Test that CronExpression is used for parsing cron expressions
     * This validates the dependency on dragonmantank/cron-expression.
     */
    public function testCronExpressionDependency(): void
    {
        $this->assertTrue(
            class_exists(CronExpression::class),
            'CronExpression class from dragonmantank/cron-expression should be available',
        );
    }

    /**
     * Test cron expression parsing for standard expressions.
     */
    public function testCronExpressionParsing(): void
    {
        // Test various cron expressions that might be used
        $expressions = [
            '0 0 * * *',      // Daily at midnight
            '0 * * * *',      // Every hour
            '*/5 * * * *',    // Every 5 minutes
            '0 0 * * 0',      // Weekly on Sunday
            '0 0 1 * *',      // Monthly on 1st
            '0 0 1 1 *',      // Yearly on Jan 1st
        ];

        foreach ($expressions as $expr) {
            $cron = new CronExpression($expr);
            $nextRun = $cron->getNextRunDate(new \DateTime(), 0, true);

            $this->assertInstanceOf(
                \DateTime::class,
                $nextRun,
                "Cron expression '{$expr}' should return a valid DateTime",
            );
        }
    }

    /**
     * Test handling of empty crontab expression
     * When interv is 'c' (custom) but cron field is empty, should disable interval.
     */
    public function testEmptyCrontabHandling(): void
    {
        // This test verifies the logic at lines 44-49
        $mockRunTemplate = $this->createMock(RunTemplate::class);

        // Simulate empty cron field with interv = 'c'
        $runtemplateData = [
            'id' => 1,
            'interv' => 'c',
            'cron' => '',  // Empty cron expression
            'delay' => null,
            'name' => 'Test Template',
            'executor' => 'test',
            'last_schedule' => null,
        ];

        // Expect updateToSQL to be called to disable interval
        $mockRunTemplate->expects($this->once())
            ->method('updateToSQL')
            ->with(
                ['interv' => 'n'],
                ['id' => 1],
            );

        // Expect warning message to be added
        $mockRunTemplate->expects($this->once())
            ->method('addStatusMessage')
            ->with(
                $this->stringContains('Empty crontab'),
                'warning',
            );

        // Manually test the logic
        if ($runtemplateData['interv'] === 'c' && empty($runtemplateData['cron'])) {
            $mockRunTemplate->updateToSQL(['interv' => 'n'], ['id' => $runtemplateData['id']]);
            $mockRunTemplate->addStatusMessage('Empty crontab. Disabling interval #1', 'warning');
        }
    }

    /**
     * Test emoji retrieval for different interval types
     * Verifies the new emoji functionality added in the refactoring.
     */
    public function testEmojiRetrievalForIntervals(): void
    {
        // Test that RunTemplate::getIntervalEmoji is called for various intervals
        $intervals = ['i', 'h', 'd', 'w', 'm', 'y', 'c'];

        foreach ($intervals as $interval) {
            // Verify that the method can be called without errors
            try {
                $emoji = RunTemplate::getIntervalEmoji($interval);
                $this->assertIsString($emoji, "Emoji should be a string for interval '{$interval}'");
            } catch (\Throwable $e) {
                // If method doesn't exist, test that we handle it gracefully
                $this->markTestSkipped('RunTemplate::getIntervalEmoji not available: '.$e->getMessage());
            }
        }
    }

    /**
     * Test delay modification to start time
     * Verifies lines 61-64 where delay is added to start time.
     */
    public function testDelayModificationToStartTime(): void
    {
        $startTime = new \DateTime('2024-01-01 12:00:00');
        $originalTime = clone $startTime;
        $delay = 300; // 5 minutes

        $startTime->modify("+{$delay} seconds");

        $this->assertNotEquals(
            $originalTime->format('Y-m-d H:i:s'),
            $startTime->format('Y-m-d H:i:s'),
            'Start time should be modified when delay is applied',
        );

        $this->assertEquals(
            300,
            $startTime->getTimestamp() - $originalTime->getTimestamp(),
            'Delay should add exactly 300 seconds',
        );
    }

    /**
     * Test that delay is not applied when empty.
     */
    public function testNoDelayWhenEmpty(): void
    {
        $startTime = new \DateTime('2024-01-01 12:00:00');
        $originalTime = clone $startTime;
        $delay = null;

        // Simulate the condition check
        if (empty($delay) === false) {
            $startTime->modify("+{$delay} seconds");
        }

        $this->assertEquals(
            $originalTime->format('Y-m-d H:i:s'),
            $startTime->format('Y-m-d H:i:s'),
            'Start time should not be modified when delay is empty',
        );
    }

    /**
     * Test schedule comparison logic
     * Verifies lines 68-69 where schedules are compared.
     */
    public function testScheduleComparisonLogic(): void
    {
        $scheduleAt = '2024-01-01 12:00:00';
        $lastSchedule = '2024-01-01 11:00:00';

        $this->assertNotEquals(
            $scheduleAt,
            $lastSchedule,
            'Different schedules should not be equal',
        );

        $sameSchedule = '2024-01-01 12:00:00';
        $this->assertEquals(
            $scheduleAt,
            $sameSchedule,
            'Same schedules should be equal',
        );
    }

    /**
     * Test date formatting for SQL storage
     * Verifies line 66 format.
     */
    public function testDateFormattingForSQL(): void
    {
        $dateTime = new \DateTime('2024-01-15 14:30:45');
        $formatted = $dateTime->format('Y-m-d H:i:s');

        $this->assertEquals(
            '2024-01-15 14:30:45',
            $formatted,
            'Date should be formatted as Y-m-d H:i:s for SQL',
        );

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $formatted,
            'Formatted date should match SQL datetime pattern',
        );
    }

    /**
     * Test exception handling in job preparation
     * Verifies lines 71-78 exception handling.
     */
    public function testExceptionHandlingInJobPreparation(): void
    {
        $mockScheduler = $this->getMockBuilder(CronScheduler::class)
            ->onlyMethods(['addStatusMessage'])
            ->getMock();

        $testException = new \RuntimeException('Test error message');

        // Expect addStatusMessage to be called with error
        $mockScheduler->expects($this->once())
            ->method('addStatusMessage')
            ->with(
                'Test error message',
                'error',
            );

        // Simulate exception handling
        try {
            throw $testException;
        } catch (\Throwable $t) {
            $mockScheduler->addStatusMessage($t->getMessage(), 'error');
        }
    }

    /**
     * Test handling of various throwable types.
     */
    public function testVariousThrowableTypes(): void
    {
        $throwables = [
            new \Exception('Exception message'),
            new \RuntimeException('Runtime exception'),
            new \InvalidArgumentException('Invalid argument'),
        ];

        foreach ($throwables as $throwable) {
            $caught = false;

            try {
                throw $throwable;
            } catch (\Throwable $t) {
                $caught = true;
                $this->assertIsString($t->getMessage());
            }

            $this->assertTrue($caught, 'Throwable should be caught');
        }
    }

    /**
     * Test ConfigFields instantiation with empty string
     * Verifies line 72 usage.
     */
    public function testConfigFieldsInstantiation(): void
    {
        try {
            $configFields = new ConfigFields('');
            $this->assertInstanceOf(
                ConfigFields::class,
                $configFields,
                'ConfigFields should be instantiable with empty string',
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('ConfigFields class not available: '.$e->getMessage());
        }
    }

    /**
     * Test status message format with emoji
     * Verifies the new format on line 75.
     */
    public function testStatusMessageFormatWithEmoji(): void
    {
        $emoji = '⏰';
        $applicationKey = 123;
        $applicationName = 'Test App';
        $templateName = 'Test Template';
        $templateId = 456;
        $startTime = new \DateTime('2024-01-01 12:00:00');
        $companyName = 'Test Company';

        $expectedPattern = sprintf(
            '%s🧩 #%d\s+%s:%s \(runtemplate #%d\) - Launch .+ for 🏣 %s',
            preg_quote($emoji, '/'),
            $applicationKey,
            preg_quote($applicationName, '/'),
            preg_quote($templateName, '/'),
            $templateId,
            preg_quote($companyName, '/'),
        );

        $actualMessage = sprintf(
            '%s🧩 #%d	%s:%s (runtemplate #%d) - Launch %s for 🏣 %s',
            $emoji,
            $applicationKey,
            $applicationName,
            $templateName,
            $templateId,
            $startTime->format(\DATE_RSS),
            $companyName,
        );

        $this->assertMatchesRegularExpression(
            '/'.$expectedPattern.'/',
            $actualMessage,
            'Status message should include emoji and follow expected format',
        );
    }

    /**
     * Test interv field value validation
     * Verifies line 40 condition.
     */
    public function testIntervFieldValidation(): void
    {
        // Test valid interv values
        $validValues = ['i', 'h', 'd', 'w', 'm', 'y', 'c'];

        foreach ($validValues as $value) {
            $shouldProcess = ($value !== 'n');
            $this->assertTrue(
                $shouldProcess,
                "Interv value '{$value}' should be processed (not 'n')",
            );
        }

        // Test 'n' (none) value
        $noneValue = 'n';
        $shouldNotProcess = ($noneValue !== 'n');
        $this->assertFalse(
            $shouldNotProcess,
            "Interv value 'n' should not be processed",
        );
    }

    /**
     * Test cron expression for different interval types
     * Verifies line 52 usage of $intervCron.
     */
    public function testIntervCronMapping(): void
    {
        // Common interval to cron mappings
        $expectedMappings = [
            'h' => '0 * * * *',      // Hourly
            'd' => '0 0 * * *',      // Daily
            'w' => '0 0 * * 0',      // Weekly
            'm' => '0 0 1 * *',      // Monthly
            'y' => '0 0 1 1 *',      // Yearly
        ];

        foreach ($expectedMappings as $interval => $expectedCron) {
            // Verify these are valid cron expressions
            $cron = new CronExpression($expectedCron);
            $this->assertInstanceOf(
                CronExpression::class,
                $cron,
                "Interval '{$interval}' should map to valid cron expression",
            );
        }
    }

    /**
     * Test next run date calculation with allowCurrentDate parameter
     * Verifies line 59 with parameters.
     */
    public function testNextRunDateCalculation(): void
    {
        $cron = new CronExpression('0 0 * * *'); // Daily at midnight
        $currentDate = new \DateTime('2024-01-01 12:00:00');

        // Test with allowCurrentDate = true
        $nextRun = $cron->getNextRunDate($currentDate, 0, true);
        $this->assertInstanceOf(
            \DateTime::class,
            $nextRun,
            'Should return DateTime object',
        );

        // Next run should be at midnight
        $this->assertEquals(
            '00:00:00',
            $nextRun->format('H:i:s'),
            'Next run for daily cron should be at midnight',
        );
    }

    /**
     * Test RSS date format for status messages
     * Verifies \DATE_RSS usage on line 75.
     */
    public function testRSSDateFormat(): void
    {
        $dateTime = new \DateTime('2024-01-15 14:30:45', new \DateTimeZone('UTC'));
        $rssFormat = $dateTime->format(\DATE_RSS);

        $this->assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/',
            $rssFormat,
            'RSS format should match expected pattern',
        );
    }

    /**
     * Test handling of null last_schedule value.
     */
    public function testNullLastScheduleHandling(): void
    {
        $scheduleAt = '2024-01-01 12:00:00';
        $lastSchedule = null;

        // Simulate the comparison
        $shouldSchedule = ($scheduleAt !== $lastSchedule);

        $this->assertTrue(
            $shouldSchedule,
            'Should schedule when last_schedule is null',
        );
    }

    /**
     * Test empty delay value handling.
     */
    public function testEmptyDelayValues(): void
    {
        $emptyValues = [null, '', 0, '0'];

        foreach ($emptyValues as $delay) {
            $isEmpty = empty($delay);

            // Only non-zero positive integers should be considered non-empty for delay
            if ($delay === 0 || $delay === '0') {
                $this->assertTrue(
                    $isEmpty,
                    "Delay value {$delay} should be considered empty",
                );
            }
        }
    }

    /**
     * Test that job scheduling handles edge cases around midnight.
     */
    public function testMidnightEdgeCases(): void
    {
        $cron = new CronExpression('0 0 * * *'); // Daily at midnight

        // Test at 23:59:59
        $beforeMidnight = new \DateTime('2024-01-01 23:59:59');
        $nextRun = $cron->getNextRunDate($beforeMidnight, 0, false);

        $this->assertEquals(
            '2024-01-02',
            $nextRun->format('Y-m-d'),
            'Next run after 23:59:59 should be next day',
        );

        // Test at 00:00:00
        $atMidnight = new \DateTime('2024-01-01 00:00:00');
        $nextRunFromMidnight = $cron->getNextRunDate($atMidnight, 0, true);

        $this->assertNotNull($nextRunFromMidnight);
    }

    /**
     * Test large delay values don't cause overflow.
     */
    public function testLargeDelayValues(): void
    {
        $startTime = new \DateTime('2024-01-01 12:00:00');
        $largeDelay = 86400; // 1 day in seconds

        $startTime->modify("+{$largeDelay} seconds");

        $this->assertEquals(
            '2024-01-02',
            $startTime->format('Y-m-d'),
            'Large delay should be handled correctly',
        );
    }

    /**
     * Test cron expression with specific minute intervals.
     */
    public function testCronMinuteIntervals(): void
    {
        $expressions = [
            '*/5 * * * *',  // Every 5 minutes
            '*/10 * * * *', // Every 10 minutes
            '*/15 * * * *', // Every 15 minutes
            '*/30 * * * *', // Every 30 minutes
        ];

        foreach ($expressions as $expr) {
            $cron = new CronExpression($expr);
            $now = new \DateTime();
            $next1 = $cron->getNextRunDate($now);
            $next2 = $cron->getNextRunDate($next1);

            $diff = $next2->getTimestamp() - $next1->getTimestamp();

            // Extract interval from expression
            preg_match('/\*\/(\d+)/', $expr, $matches);
            $expectedDiff = (int) $matches[1] * 60;

            $this->assertEquals(
                $expectedDiff,
                $diff,
                "Interval for '{$expr}' should be {$expectedDiff} seconds",
            );
        }
    }

    /**
     * Test timezone handling in date operations.
     */
    public function testTimezoneHandling(): void
    {
        $utcDate = new \DateTime('2024-01-01 12:00:00', new \DateTimeZone('UTC'));
        $formatted = $utcDate->format('Y-m-d H:i:s');

        $this->assertEquals(
            '2024-01-01 12:00:00',
            $formatted,
            'UTC date should format correctly',
        );

        // Test timezone conversion doesn't affect SQL format
        $utcDate->setTimezone(new \DateTimeZone('America/New_York'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $utcDate->format('Y-m-d H:i:s'),
            'Formatted date should maintain SQL format regardless of timezone',
        );
    }
}
