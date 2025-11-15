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
$scheduler->logBanner(sprintf(_('MultiFlexi Schedule Daemon %s started'), \Ease\Shared::appVersion()));

date_default_timezone_set('Europe/Prague');

do {
    $scheduler->scheduleCronJobs();
    sleep(1);
} while ($daemonize);

$scheduler->logBanner('MultiFlexi Daemon ended');
