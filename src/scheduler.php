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

use Ease\Shared;

require_once '../vendor/autoload.php';

if (file_exists('/usr/share/php/MultiFlexi/autoload.php')) {
    require_once '/usr/share/php/MultiFlexi/autoload.php';
}

Shared::init(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], '../.env');
date_default_timezone_set(DateTimeHelper::getConfiguredTimezoneString());
$loggers = ['syslog', '\MultiFlexi\LogToSQL'];

if (Shared::cfg('ZABBIX_SERVER') && Shared::cfg('ZABBIX_HOST') && class_exists('\MultiFlexi\LogToZabbix')) {
    $loggers[] = '\MultiFlexi\LogToZabbix';
}

if (strtolower(Shared::cfg('APP_DEBUG', 'False')) === 'true') {
    $loggers[] = 'console';
}

\define('EASE_LOGGER', implode('|', $loggers));
$interval = $argc === 2 ? $argv[1] : null;
\define('APP_NAME', 'Schedule MultiFlexi RunTemplate');

new \MultiFlexi\Defaults();
\Ease\Shared::user(new \MultiFlexi\UnixUser());

$jobber = new Job();

if (strtolower(Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $jobber->logBanner();
}

if (\MultiFlexi\Runner::isServiceActive('multiflexi-executor') === false) {
    $jobber->addStatusMessage(_('systemd service is not running. Consider `systemctl start multiflexi-executor`'), 'warning');
}

if ($interval) {
    $emoji = \MultiFlexi\Scheduler::getIntervalEmoji($interval);
    $runtemplate = new \MultiFlexi\RunTemplate();
    $companyNames = (new Company())->getColumnsFromSQL(['id', 'name'], null, null, 'id');

    $appsForInterval = $runtemplate->getColumnsFromSQL(['id', 'interv', 'delay', 'name', 'executor', 'company_id'], ['interv' => $interval, 'active' => true]);

    foreach ($appsForInterval as $runtemplateData) {
        $companyName = $companyNames[$runtemplateData['company_id']]['name'] ?? $runtemplateData['company_id'];
        LogToSQL::singleton()->setCompany($runtemplateData['company_id']);

        if (strtolower(Shared::cfg('APP_DEBUG', 'false')) === 'true') {
            $jobber->addStatusMessage($emoji.' '.sprintf(_('%s Scheduler interval %s begin'), $companyName, \MultiFlexi\Scheduler::$intervCron[$interval].' ('.$interval.')'), 'debug');
        }

        $startTime = new \DateTime();

        if (empty($runtemplateData['delay']) === false) {
            $startTime->modify('+'.$runtemplateData['delay'].' seconds');
            $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
        }

        $runtemplate->setData($runtemplateData);
        $jobber->prepareJob($runtemplate, new ConfigFields(''), $startTime, $runtemplateData['executor'], Scheduler::codeToInterval($interval));
        // scheduleJobRun() is now called automatically inside prepareJob()
        $jobber->addStatusMessage($emoji.' 🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $companyName));

        if (Shared::cfg('APP_DEBUG') === 'true') {
            $jobber->addStatusMessage($emoji.' '.sprintf(_('%s Scheduler interval %s end'), $companyName, Scheduler::codeToInterval($interval)), 'debug');
        }
    }
} else {
    echo "interval i/y/m/w/d/h missing\n";

    exit(1);
}
