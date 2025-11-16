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
            $appsForCompany = $runtemplate->getColumnsFromSQL(['id', 'cron', 'delay', 'name', 'executor', 'next_schedule', 'interv'], ['company_id' => $company['id'], 'active' => true, 'next_schedule' => null, 'interv != ?' => 'n']);

            foreach ($appsForCompany as $runtemplateData) {
                $emoji = RunTemplate::getIntervalEmoji($runtemplateData['interv']);

                if ($runtemplateData['interv'] === 'c') {
                    if (empty($runtemplateData['cron'])) {
                        $runtemplate->updateToSQL(['interv' => 'n'], ['id' => $runtemplateData['id']]);
                        $runtemplate->addStatusMessage(_('Empty crontab. Disabling interval').' #'.$runtemplateData['id'], 'warning');

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

                $startTime = $cron->getNextRunDate(new \DateTime(), 0, true);

 if (empty($runtemplateData['delay']) === false) {
     $startTime->modify('+'.$runtemplateData['delay'].' seconds');

                if (empty($runtemplateData['delay']) === false) {
                    $startTime->modify('+'.$runtemplateData['delay'].' seconds');
                    $jobber->addStatusMessage($emoji.' Adding Startup delay  +'.$runtemplateData['delay'].' seconds to '.$startTime->format('Y-m-d H:i:s'), 'debug');
                }

                try {
                    $jobber->prepareJob((int) $runtemplateData['id'], new ConfigFields(''), $startTime, $runtemplateData['executor'], 'custom');
                    $jobber->scheduleJobRun($startTime);

                    $jobber->addStatusMessage($emoji.'🧩 #'.$jobber->application->getMyKey()."\t".$jobber->application->getRecordName().':'.$runtemplateData['name'].' (runtemplate #'.$runtemplateData['id'].') - '.sprintf(_('Launch %s for 🏣 %s'), $startTime->format(\DATE_RSS), $company['name']));
                } catch (\Throwable $t) {
                    $this->addStatusMessage($t->getMessage(), 'error');
                }
            }
        }
    }
}
