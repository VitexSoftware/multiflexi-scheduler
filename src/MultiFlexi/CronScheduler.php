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
        $this->addStatusMessage('scheduleCronJobs() entry; memory: '.self::formatMemory(memory_get_usage(true)), 'debug');
        \MultiFlexi\Task::finalizeExpired();
        $companyNames = (new Company())->getColumnsFromSQL(['id', 'name'], null, null, 'id');
        $this->addStatusMessage('Companies listing obtained', 'debug');
        $jobber = new Job();
        $runtemplateQuery = new \MultiFlexi\RunTemplate();
        $runtemplateQuery->lastModifiedColumn = null;

        $rtFields = ['id', 'cron', 'delay', 'name', 'executor', 'last_schedule', 'interv', 'app_id', 'company_id'];

        $periodicRuntemplates = $runtemplateQuery->getColumnsFromSQL($rtFields, ['active' => true, 'next_schedule' => null, 'interv != ?' => 'n']);

        foreach ($periodicRuntemplates as $runtemplateData) {
            $companyName = $companyNames[$runtemplateData['company_id']]['name'] ?? $runtemplateData['company_id'];
            LogToSQL::singleton()->setCompany($runtemplateData['company_id']);
            $this->addStatusMessage('Considering runtemplate #'.$runtemplateData['id'].' '.\MultiFlexi\CronDescriber::describe($runtemplateData['interv'], $runtemplateData['cron']), 'debug');

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

            $candidate = $cron->getPreviousRunDate(new \DateTime(), 0, true);

            $alreadyHandled = $jobber->listingQuery()
                ->where('runtemplate_id', $runtemplateData['id'])
                ->where('schedule', $candidate->format('Y-m-d H:i:s'))
                ->fetch();

            $startTime = $alreadyHandled
                ? $cron->getNextRunDate($candidate, 0, false)
                : $candidate;

            // Task tracking uses the cron-tick boundary as the window start,
            // before the startup delay shifts the actual launch instant.
            $windowStart = clone $startTime;

            if (empty($runtemplateData['delay']) === false) {
                $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
            }

            // Only fixed-interval RunTemplates (y/m/w/d/h/i) have a well-defined
            // cadence window; custom cron (interv = 'c') is not tracked as a Task.
            $jobber->setDataValue('task_id', null); // TODO:

            if (\MultiFlexi\Scheduler::codeToSeconds($runtemplateData['interv']) > 0) {
                try {
                    $task = \MultiFlexi\Task::findForWindow((int) $runtemplateData['id'], $windowStart)
                        ?? \MultiFlexi\Task::materialize(new \MultiFlexi\RunTemplate((int) $runtemplateData['id']), $windowStart);
                    $jobber->setDataValue('task_id', $task->getMyKey());
                } catch (\Throwable $taskError) {
                    $this->addStatusMessage('Task materialization failed for runtemplate #'.$runtemplateData['id'].': '.$taskError->getMessage(), 'warning');
                }
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

                // Idempotency guard: whatever upstream condition made this runtemplate
                // a candidate again, never create a second job for the same slot while
                // one is still pending/running for it.
                $existingJob = (new \MultiFlexi\Job())->listingQuery()
                    ->where('runtemplate_id', $runtemplateData['id'])
                    ->where('schedule', $startTime->format('Y-m-d H:i:s'))
                    ->where('exitcode IS NULL')
                    ->fetch();

                if ($existingJob) {
                    $this->addStatusMessage('Skipping duplicate job creation for runtemplate #'.$runtemplateData['id'].' at '.$startTime->format('Y-m-d H:i:s').' — job #'.$existingJob['id'].' already pending', 'warning');

                    // The job itself is not recreated, but it must still show up in
                    // `multiflexi queue:list` — addJob() is idempotent, so re-queuing
                    // an already-scheduled job is a no-op that just confirms presence.
                    (new \MultiFlexi\Job((int) $existingJob['id']))->scheduleJobRun($startTime);

                    continue;
                }

                // Attach task_id before prepareJob so newJob() picks it up
                $jobber->setDataValue('task_id', $task->getMyKey());
                $jobber->prepareJob($runtemplate, new ConfigFields(''), $startTime, $runtemplateData['executor'], \MultiFlexi\Scheduler::codeToInterval($runtemplateData['interv']));
                // scheduleJobRun() is now called automatically inside prepareJob()
                $task->markRunning();

                $jobber->addStatusMessage($emoji.'🧩 #'.$jobber->getApplication()->getMyKey()."\t".$jobber->getApplication()->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $companyName));
                $this->addStatusMessage('Job prepared for runtemplate #'.$runtemplateData['id'].' memory: '.self::formatMemory(memory_get_usage(true)), 'debug');
            } catch (\Throwable $t) {
                $this->addStatusMessage($t->getMessage(), 'error');
            }
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
