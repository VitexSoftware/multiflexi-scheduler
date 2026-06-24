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
use MultiFlexi\Task;

/**
 * Description of CronScheduler.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class CronScheduler extends \MultiFlexi\Scheduler
{
    public function scheduleCronJobs(): void
    {
        $this->addStatusMessage('scheduleCronJobs() entry; memory: '.self::formatMemory(memory_get_usage(true)), 'debug');
        $companer = new Company();
        $companies = $companer->listingQuery();
        $this->addStatusMessage('Companies listing obtained', 'debug');
        $jobber = new Job();
        $runtemplateQuery = new \MultiFlexi\RunTemplate();
        $runtemplateQuery->lastModifiedColumn = null;

        $rtFields = ['id', 'cron', 'delay', 'name', 'executor', 'last_schedule', 'interv', 'app_id', 'company_id'];

        foreach ($companies as $company) {
            $this->addStatusMessage('Processing company #'.$company['id'].' memory: '.self::formatMemory(memory_get_usage(true)), 'debug');
            LogToSQL::singleton()->setCompany($company['id']);

            $appsForCompany = $runtemplateQuery->getColumnsFromSQL($rtFields, ['company_id' => $company['id'], 'active' => true, 'next_schedule' => null, 'interv != ?' => 'n']);

            foreach ($appsForCompany as $runtemplateData) {
                $this->addStatusMessage('Considering runtemplate #'.$runtemplateData['id'], 'debug');

                $runtemplate = new \MultiFlexi\RunTemplate();
                $emoji = \MultiFlexi\Scheduler::getIntervalEmoji($runtemplateData['interv']);

                if ($runtemplateData['interv'] === 'c') {
                    if (empty($runtemplateData['cron'])) {
                        $runtemplateQuery->updateToSQL(['interv' => 'n'], ['id' => $runtemplateData['id']]);
                        $runtemplateQuery->addStatusMessage(_('Empty crontab. Disabling interval').' #'.$runtemplateData['id'], 'warning');

                        continue;
                    }
                } else {
                    $this->setDataValue($this->nameColumn, $emoji.' '.$this->getDataValue($this->nameColumn));
                    $runtemplateData['cron'] = self::$intervCron[$runtemplateData['interv']];
                }

                $runtemplate->setData($runtemplateData);
                $runtemplate->setObjectName();

                $cron = new CronExpression($runtemplateData['cron']);

                // If this runtemplate ran before, compute the next occurrence AFTER the
                // last run so we never re-schedule for the same cron period. Without
                // this guard, a fast job would finish, next_schedule would be reset to
                // null, and the scheduler would immediately create another job for the
                // same (already-past) cron firing, causing duplicate runs within one
                // cron period.
                if (!empty($runtemplateData['last_schedule'])) {
                    $lastRun = new \DateTime($runtemplateData['last_schedule']);
                    $startTime = $cron->getNextRunDate($lastRun, 0, false);
                } else {
                    $startTime = $cron->getNextRunDate(new \DateTime(), 0, true);
                }

                if (empty($runtemplateData['delay']) === false) {
                    $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                    $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
                }

                try {
                    $this->addStatusMessage('Preparing job for runtemplate #'.$runtemplateData['id'].' start: '.$startTime->format('Y-m-d H:i:s'), 'debug');

                    if (empty($runtemplateData['company_id'])) {
                        $this->addStatusMessage(sprintf(_('Runtemplate #%d has no company_id in scheduleCronJobs'), $runtemplateData['id']), 'warning');
                    }

                    // Materialize a Task for this scheduling window (idempotent — skip if one exists)
                    $windowStart = clone $startTime;

                    if (!empty($runtemplateData['delay'])) {
                        $windowStart->modify('-'.$runtemplateData['delay'].' seconds');
                    }

                    $existingTask = Task::findForWindow((int) $runtemplateData['id'], $windowStart);

                    if ($existingTask === null) {
                        $task = Task::materialize($runtemplate, $windowStart);
                        $this->addStatusMessage('Task #'.$task->getMyKey().' materialized for runtemplate #'.$runtemplateData['id'], 'debug');
                    } else {
                        $task = $existingTask;
                        $this->addStatusMessage('Reusing existing task #'.$task->getMyKey().' for runtemplate #'.$runtemplateData['id'], 'debug');
                    }

                    // Attach task_id before prepareJob so newJob() picks it up
                    $jobber->setDataValue('task_id', $task->getMyKey());
                    $jobber->prepareJob($runtemplate, new ConfigFields(''), $startTime, $runtemplateData['executor'], 'custom');
                    // scheduleJobRun() is now called automatically inside prepareJob()
                    $task->markRunning();

                    $jobber->addStatusMessage($emoji.'🧩 #'.$jobber->getApplication()->getMyKey()."\t".$jobber->getApplication()->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
                    $this->addStatusMessage('Job prepared for runtemplate #'.$runtemplateData['id'].' memory: '.self::formatMemory(memory_get_usage(true)), 'debug');
                } catch (\Throwable $t) {
                    $this->addStatusMessage($t->getMessage(), 'error');
                }
            }

            $this->addStatusMessage('Finished processing company #'.$company['id'].' memory: '.self::formatMemory(memory_get_usage(true)), 'debug');
        }

        // Finalize tasks whose window has expired without fulfilment
        Task::finalizeExpired();

        $this->addStatusMessage('scheduleCronJobs() exit; memory: '.self::formatMemory(memory_get_usage(true)), 'debug');
    }

    /**
     * Format bytes into human readable string (KB, MB, GB).
     */
    private static function formatMemory(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $bytes /= 1024;

        foreach ($units as $unit) {
            if ($bytes < 1024) {
                return sprintf('%.2f %s', $bytes, $unit);
            }

            $bytes /= 1024;
        }

        return sprintf('%.2f PB', $bytes);
    }
}
