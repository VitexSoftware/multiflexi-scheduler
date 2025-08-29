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

        foreach ($companies as $company) {
            LogToSQL::singleton()->setCompany($company['id']);
            $appsForCompany = $runtemplate->getColumnsFromSQL(['id', 'cron', 'delay', 'name', 'executor', 'last_schedule'], ['company_id' => $company['id'], 'active' => true]);

            foreach ($appsForCompany as $runtemplateData) {
                $runtemplate->setData($runtemplateData);
                if (empty($runtemplateData['cron'])) {
                    continue;
                }

                $cron = new CronExpression($runtemplateData['cron']);
                $now = new \DateTime();

                if ($cron->isDue($now)) {
                    // Anchor scheduling to the cron window start so we don't re-schedule once per second
                    // within the same window. Using the next run date from (now - 1 minute) yields a
                    // stable timestamp for the current due window across the entire minute.
                    $windowStart = $cron->getNextRunDate((clone $now)->modify('-1 minute'));
                    $startTime = clone $windowStart;

                    if (!empty($runtemplateData['delay'])) {
                        $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                    }

                    // Guard against re-scheduling within the same cron window using DB-backed last_schedule
                    $lastScheduleRaw = $runtemplateData['last_schedule'] ?? null;
                    $alreadyScheduledThisWindow = false;

                    if ($lastScheduleRaw) {
                        try {
                            $lastSchedule = new \DateTime($lastScheduleRaw);
                            if ($lastSchedule >= $windowStart) {
                                $alreadyScheduledThisWindow = true;
                            }
                        } catch (\Exception $e) {
                            // ignore parse issues and proceed to schedule
                        }
                    }

                    if ($alreadyScheduledThisWindow) {
                        continue; // Skip: this cron window was already handled
                    }

                    // Persist the fact that we're scheduling this window to avoid rapid duplicates
                    try {
                        $runtemplate->updateToSQL(['last_schedule' => $windowStart->format('Y-m-d H:i:s')], ['id' => (int) $runtemplateData['id']]);
                    } catch (\Throwable $t) {
                        // If we cannot update, fall back to isScheduled guard only
                    }

                    // Check if job already scheduled for this time and runtemplate (extra guard)
                    if (! $runtemplate->isScheduled($startTime) ) {
                        $jobber->prepareJob((int) $runtemplateData['id'], new ConfigFields(''), $startTime, $runtemplateData['executor'], 'cron');
                        $jobber->scheduleJobRun($startTime);
                        $jobber->addStatusMessage('🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
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

                    $startTime = new \\DateTime();

                    if (empty($runtemplateData['delay']) === false) {
                        $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                        $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
                    }

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
