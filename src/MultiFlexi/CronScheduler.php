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
        $this->addStatusMessage('scheduleCronJobs() entry; memory: '. $this->formatMemory(memory_get_usage(true)), 'debug');
        $companer = new Company();
        $companies = $companer->listingQuery();
        $this->addStatusMessage('Companies listing obtained', 'debug');
        $jobber = new Job();
        $runtemplateQuery = new \MultiFlexi\RunTemplate();
        $runtemplateQuery->lastModifiedColumn = null;

        $rtFields = ['id', 'cron', 'delay', 'name', 'executor', 'last_schedule', 'interv', 'app_id', 'company_id'];

        foreach ($companies as $company) {
            $this->addStatusMessage('Processing company #'.$company['id'].' memory: '. $this->formatMemory(memory_get_usage(true)), 'debug');
            LogToSQL::singleton()->setCompany($company['id']);

            $appsForCompany = $runtemplateQuery->getColumnsFromSQL($rtFields, ['company_id' => $company['id'], 'active' => true, 'next_schedule' => null, 'interv != ?' => 'n']);

            foreach ($appsForCompany as $runtemplateData) {
                $this->addStatusMessage('Considering runtemplate #'.$runtemplateData['id'], 'debug');
                // Check if there's already a pending scheduled job for this runtemplate
                // (exclude adhoc jobs by checking if schedule field is not null)
                $existingJob = $jobber->listingQuery()
                    ->where(['runtemplate_id' => $runtemplateData['id'], 'exitcode' => null])
                    ->where('schedule IS NOT NULL')
                    ->fetch();

                if ($existingJob) {
                    // Skip if there's already a pending scheduled job for this runtemplate
                    $this->addStatusMessage('Skipping runtemplate #'.$runtemplateData['id'].' - existing pending job', 'debug');
                    continue;
                }

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

                $startTime = $cron->getNextRunDate(new \DateTime(), 0, true);

                if (empty($runtemplateData['delay']) === false) {
                    $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                    $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
                }

                try {
                    $this->addStatusMessage('Preparing job for runtemplate #'.$runtemplateData['id'].' start: '.$startTime->format('Y-m-d H:i:s'), 'debug');
                    if (empty($runtemplateData['company_id'])) {
                        $this->addStatusMessage(sprintf(_('Runtemplate #%d has no company_id in scheduleCronJobs'), $runtemplateData['id']), 'warning');
                    }

                    $jobber->prepareJob($runtemplate, new ConfigFields(''), $startTime, $runtemplateData['executor'], 'custom');
                    // scheduleJobRun() is now called automatically inside prepareJob()

                    $jobber->addStatusMessage($emoji.'🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
                    $this->addStatusMessage('Job prepared for runtemplate #'.$runtemplateData['id'].' memory: '. $this->formatMemory(memory_get_usage(true)), 'debug');
                } catch (\Throwable $t) {
                    $this->addStatusMessage($t->getMessage(), 'error');
                }
            }
            $this->addStatusMessage('Finished processing company #'.$company['id'].' memory: '. $this->formatMemory(memory_get_usage(true)), 'debug');
        }
        $this->addStatusMessage('scheduleCronJobs() exit; memory: '. $this->formatMemory(memory_get_usage(true)), 'debug');
    }

    /**
     * Format bytes into human readable string (KB, MB, GB).
     */
    private function formatMemory(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $bytes = $bytes / 1024;
        foreach ($units as $unit) {
            if ($bytes < 1024) {
                return sprintf('%.2f %s', $bytes, $unit);
            }
            $bytes = $bytes / 1024;
        }
        return sprintf('%.2f PB', $bytes);
    }
}
