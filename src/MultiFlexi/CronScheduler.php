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

use Cron\CronExpression;

/**
 * Description of CronScheduler.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class CronScheduler extends \MultiFlexi\Scheduler
{
    public function scheduleCronJobs(): void
    {
        $companer = new Company();
        $companies = $companer->listingQuery();
        $jobber = new Job();
        $runtemplate = new \MultiFlexi\RunTemplate();
        $runtemplate->lastModifiedColumn = null;

        foreach ($companies as $company) {
            LogToSQL::singleton()->setCompany($company['id']);
            $appsForCompany = $runtemplate->getColumnsFromSQL(['id', 'cron', 'delay', 'name', 'executor', 'last_schedule', 'interv'], ['company_id' => $company['id'], 'active' => true, 'next_schedule' => null]);

            foreach ($appsForCompany as $runtemplateData) {
                if ($runtemplateData['interv'] !== 'n') {
                    if ($runtemplateData['interv'] === 'c' && empty($runtemplateData['cron'])) {
                        $runtemplate->updateToSQL(['interv' => 'n'], ['id' => $runtemplateData['id']]);
                        $runtemplate->addStatusMessage(_('Empty crontab. Disabling interval').' #'.$runtemplateData['id'], 'warning');

                        continue;
                    }

                    if ($runtemplateData['interv'] !== 'c') {
                        $runtemplateData['cron'] = self::$intervCron[$runtemplateData['interv']];
                    }

                    $runtemplate->setData($runtemplateData);

                    $cron = new CronExpression($runtemplateData['cron']);

                    $startTime = $cron->getNextRunDate(new \DateTime(), 0, true);

                    if (empty($runtemplateData['delay']) === false) {
                        $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                        $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
                    }

                    $scheduleAt = $startTime->format('Y-m-d H:i:s');

                    if ($scheduleAt !== $runtemplateData['last_schedule']) {
                        $runtemplate->updateToSQL(['next_schedule' => $scheduleAt], ['id' => $runtemplateData['id']]);

                        try {
                            $jobber->prepareJob((int) $runtemplateData['id'], new ConfigFields(''), $startTime, $runtemplateData['executor'], 'custom');
                            $jobber->scheduleJobRun($startTime);

                            $jobber->addStatusMessage('🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
                        } catch (\Throwable $t) {
                            $this->addStatusMessage($t->getMessage(), 'error');
                        }
                    }
                }
            }
        }
    }

    public function scheduleIntervalJobs(string $interval): void
    {
        $emoji = RunTemplate::getIntervalEmoji($interval);
        $companer = new Company();
        $companies = $companer->listingQuery();

        $jobber = new Job();
        $runtemplate = new \MultiFlexi\RunTemplate();

        foreach ($companies as $company) {
            LogToSQL::singleton()->setCompany($company['id']);

            $appsForCompany = $runtemplate
                ->listingQuery()
                ->select(['id', 'interv', 'delay', 'name', 'executor'])
                ->where('company_id', $company['id'])
                ->where('interv', $interval)
                ->where('active', true)
                ->fetchAll();

            if (empty($appsForCompany) && ($interval !== 'i')) {
                $companer->addStatusMessage($emoji.' '.sprintf(_('No applications to run for %s in interval %s'), $company['name'], $interval), 'debug');
            } else {
                if (strtolower(\Ease\Shared::cfg('APP_DEBUG', 'false')) === 'true') {
                    $jobber->addStatusMessage($emoji.' '.sprintf(_('%s Scheduler interval %s begin'), $company['name'], $interval), 'debug');
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

                    $runtemplate->updateToSQL(['next_schedule' => $startTime->format('Y-m-d H:i:s')], ['id' => $runtemplateData['id']]);
                    $jobber->prepareJob((int) $runtemplateData['id'], new ConfigFields(''), $startTime, $runtemplateData['executor'], RunTemplate::codeToInterval($interval));
                    $jobber->scheduleJobRun($startTime);
                    $jobber->addStatusMessage($emoji.' 🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
                }

                if (strtolower(\Ease\Shared::cfg('APP_DEBUG', 'false')) === 'true') {
                    $jobber->addStatusMessage($emoji.' '.sprintf(_('%s Scheduler interval %s end'), $company['name'], RunTemplate::codeToInterval($interval)), 'debug');
                }
            }
        }
    }
}
