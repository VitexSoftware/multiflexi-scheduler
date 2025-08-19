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

namespace MultiFlexi;

require_once '../vendor/autoload.php';

\define('APP_NAME', 'MultiFlexi Schedule Daemon');
\Ease\Shared::init(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], '../.env');
$daemonize = (bool) \Ease\Shared::cfg('MULTIFLEXI_DAEMONIZE', true);
$loggers = ['syslog', '\MultiFlexi\LogToSQL'];

if (\Ease\Shared::cfg('ZABBIX_SERVER') && \Ease\Shared::cfg('ZABBIX_HOST') && class_exists('\MultiFlexi\LogToZabbix')) {
    $loggers[] = '\MultiFlexi\LogToZabbix';
}

if (strtolower(\Ease\Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $loggers[] = 'console';
}

\define('EASE_LOGGER', implode('|', $loggers));
\Ease\Shared::user(new \Ease\Anonym());

function waitForDatabase(): void
{
    $try = 0;

    while (true) {
        try {
            $testScheduler = new Scheduler();
            $testScheduler->getCurrentJobs(); // Try a simple query
            unset($testScheduler);

            break;
        } catch (\Throwable $e) {
            if ($try++ < 6) {
                error_log('Database unavailable: '.$e->getMessage());
                sleep(10 * $try);
            } else {
                throw new \RuntimeException('Database unavailable: '.$e->getMessage());
            }
        }
    }
}

waitForDatabase();
$scheduler = new CronScheduler();
$scheduler->logBanner('MultiFlexi Schedule Daemon started');

date_default_timezone_set('Europe/Prague');

// Výchozí časy pro další spuštění jednotlivých úloh
$nextMinute = (new \DateTime())->modify('+1 minute')->setTime((int) date('H'), (int) date('i') + 1, 0);
$nextHour = (new \DateTime())->modify('+1 hour')->setTime((int) date('H') + 1, 0, 0);
$nextDay = (new \DateTime('tomorrow'))->setTime(0, 0, 0);
$nextWeek = clone $nextDay;

while ((int) $nextWeek->format('N') !== 1) {
    $nextWeek->modify('+1 day');
}

$nextMonth = (new \DateTime('first day of next month'))->setTime(0, 0, 0);
$nextYear = (new \DateTime('first day of January next year'))->setTime(0, 0, 0);

do {
    $now = new \DateTime();

    if ($now >= $nextMinute) { // Minutely
        $nextMinute->modify('+1 minute');
        $scheduler->scheduleIntervalJobs('i');
    }

    if ($now >= $nextHour) { // Hourly
        $nextHour->modify('+1 hour');
        $scheduler->scheduleIntervalJobs('h');
    }

    if ($now >= $nextDay) { // Daily
        $nextDay->modify('+1 day');
        $scheduler->scheduleIntervalJobs('d');

        if ((int) $now->format('w') === 0) { // Weekly
            $nextWeek->modify('+7 days');
            $scheduler->scheduleIntervalJobs('w');
        }

        $tomorrow = (clone $now)->modify('+1 day');

        if ((int) $tomorrow->format('j') === 1) { // Monthly
            $nextMonth = (new \DateTime('first day of next month'))->setTime(0, 0, 0);
            $scheduler->scheduleIntervalJobs('m');
        }

        if ((int) $tomorrow->format('z') === 0) { // Yearly
            $nextYear = (new \DateTime('first day of January next year'))->setTime(0, 0, 0);
            $scheduler->scheduleIntervalJobs('y');
        }
    }

    
    $scheduler->scheduleCronJobs();
    
    usleep(100_000); // 0.1 sekundy, šetří CPU
} while ($daemonize);

$scheduler->logBanner('MultiFlexi Daemon ended');
