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

date_default_timezone_set('Europe/Prague');

require_once '../vendor/autoload.php';
Shared::init(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], '../.env');
$loggers = ['syslog', '\MultiFlexi\LogToSQL'];

if (Shared::cfg('ZABBIX_SERVER') && Shared::cfg('ZABBIX_HOST') && class_exists('\MultiFlexi\LogToZabbix')) {
    $loggers[] = '\MultiFlexi\LogToZabbix';
}

if (strtolower(Shared::cfg('APP_DEBUG', 'False')) === 'true') {
    $loggers[] = 'console';
}

\define('EASE_LOGGER', implode('|', $loggers));
$interval = $argc === 2 ? $argv[1] : null;
\define('APP_NAME', 'MultiFlexi scheduler '.Scheduler::codeToInterval($interval));

new \MultiFlexi\Defaults();
\Ease\Shared::user(new \MultiFlexi\UnixUser());

$jobber = new Job();

if (strtolower(Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $jobber->logBanner();
}

if (\MultiFlexi\Runner::isServiceActive('multiflexi-executor') === false) {
    $jobber->addStatusMessage(_('systemd service is not running. Consider `systemctl start multiflexi-executor`'), 'warning');
}

$companer = new Company();
$companies = $companer->listingQuery();

if ($interval) {
    $emoji = \MultiFlexi\Scheduler::getIntervalEmoji($interval);
    $runtemplate = new \MultiFlexi\RunTemplate();

    foreach ($companies as $company) {
        LogToSQL::singleton()->setCompany($company['id']);

        $appsForCompany = $runtemplate->getColumnsFromSQL(['id', 'interv', 'delay', 'name', 'executor'], ['company_id' => $company['id'], 'interv' => $interval, 'active' => true]);

        if (empty($appsForCompany) && ($interval !== 'i')) {
            $companer->addStatusMessage($emoji.' '.sprintf(_('No applications to run for %s in interval %s'), $company['name'], $interval), 'debug');
        } else {
            if (strtolower(Shared::cfg('APP_DEBUG', 'false')) === 'true') {
                $jobber->addStatusMessage($emoji.' '.sprintf(_('%s Scheduler interval %s begin'), $company['name'], \MultiFlexi\Scheduler::$intervCron[$interval].' ('.$interval.')'), 'debug');
            }

            foreach ($appsForCompany as $runtemplateData) {
                if (null !== $interval && ($interval !== $runtemplateData['interv'])) {
                    continue;
                }

                $startTime = new \DateTime();

                if (empty($runtemplateData['delay']) === false) {
                    $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                    $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
                }

                $runtemplate->setData($runtemplateData);
                $jobber->prepareJob($runtemplate, new ConfigFields(''), $startTime, $runtemplateData['executor'], Scheduler::codeToInterval($interval));
                // scheduleJobRun() is now called automatically inside prepareJob()
                $jobber->addStatusMessage($emoji.' 🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
            }

            if (Shared::cfg('APP_DEBUG') === 'true') {
                $jobber->addStatusMessage($emoji.' '.sprintf(_('%s Scheduler interval %s end'), $company['name'], Scheduler::codeToInterval($interval)), 'debug');
            }
        }
    }
} else {
    echo "interval i/y/m/w/d/h missing\n";

    exit(1);
}
