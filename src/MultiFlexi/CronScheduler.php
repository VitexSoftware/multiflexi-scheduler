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
            $appsForCompany = $runtemplate->getColumnsFromSQL(['id', 'cron', 'delay', 'name', 'executor', 'last_schedule'], ['company_id' => $company['id'], 'active' => true, 'next_schedule' => null, 'interv' => 'c']);

            foreach ($appsForCompany as $runtemplateData) {
                if (empty($runtemplateData['cron'])) {
                    $runtemplate->updateToSQL(['interv' => 'n'], ['id' => $runtemplateData['id']]);
                    $runtemplate->addStatusMessage(_('Empty crontab. Disabling interval').' #'.$runtemplateData['id'], 'warning');

                    continue;
                }

                $runtemplate->setData($runtemplateData);
                $cron = new CronExpression($runtemplateData['cron']);
                $startTime = $cron->getNextRunDate(new \DateTime(), 0, true);

                try {
                    $runtemplate->updateToSQL(['next_schedule' => $startTime->format('Y-m-d H:i:s')], ['id' => $runtemplateData['id']]);

                    $jobber->prepareJob((int) $runtemplateData['id'], new ConfigFields(''), $startTime, $runtemplateData['executor'], RunTemplate::codeToInterval($interval));
                    $jobber->scheduleJobRun($startTime);

                    $jobber->addStatusMessage('🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
                } catch (\Throwable $t) {
                    // If we cannot update, fall back to isScheduled guard only
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

            // Exclude runtemplates that have explicit cron set to avoid double scheduling
            $appsForCompany = $runtemplate
                ->listingQuery()
                ->select(['id', 'interv', 'delay', 'name', 'executor'])
                ->where('company_id', $company['id'])
                ->where('interv', $interval)
                ->where('active', true)
                ->where('cron IS NULL OR cron = ?', '')
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

                    $jobber->prepareJob((int) $runtemplateData['id'], new ConfigFields(''), $startTime, $runtemplateData['executor'], 'custom');
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
