<?php
/**
 * SAM-4465 : Refactor reminder classes
 * https://bidpath.atlassian.net/browse/SAM-4465
 *
 * @author        Imran Rahman
 * @version       SVN: $Id: $
 * @since         Sept 30, 2018
 * @copyright     Copyright 2018 by Bidpath, Inc. All rights reserved.
 * File Encoding  UTF-8
 *
 * Bidpath, Inc., 269 Mt. Hermon Road #102, Scotts Valley, CA 95066, USA
 * Phone: ++1 (415) 543 5825, <info@bidpath.com>
 *
 */

namespace Sam\Reminder\Base;

use QDateTime;
use Sam\Date\DateHelperAwareTrait;
use Sam\Settings\SettingsManagerAwareTrait;

abstract class ReminderBase extends \CustomizableClass
{
    use SettingsManagerAwareTrait;
    use DateHelperAwareTrait;

    /**
     * @var QDateTime
     */
    protected $dttLastRun;
    /**
     * @var int
     */
    protected $scriptInterval;
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $templateName;

    /**
     * @var int
     */
    protected $statRemindedUsers;

    /**
     * @var int
     */
    protected $emailFrequency;

    /**
     * @return QDateTime
     */
    protected $currentDateGmtTime;

    /**
     * @return QDateTime
     */
    public function getDttLastRun()
    {
        return $this->dttLastRun;
    }

    /**
     * @return int
     */
    public function getScriptInterval()
    {
        return $this->scriptInterval;
    }

    /**
     * @param QDateTime $dttLastRun
     * @return $this
     */
    public function setDttLastRun($dttLastRun)
    {
        $this->dttLastRun = $dttLastRun;
        return $this;
    }

    /**
     * @param int $scriptInterval
     * @return $this
     */
    public function setScriptInterval($scriptInterval)
    {
        $this->scriptInterval = $scriptInterval;
        return $this;
    }

    /**
     * @param int $emailFrequency
     * @return $this
     */
    public function setEmailFrequency($emailFrequency)
    {
        $this->emailFrequency = $emailFrequency;
        return $this;
    }

    /**
     * @return bool
     */
    public function checkNotExpiredTimeoutOrFrequencySet()
    {
        $blnFirstTime = false;
        $dttLastRunPlusFreq = null;
        if (!$this->dttLastRun) {
            $blnFirstTime = true;
        } else {
            $dttLastRunPlusFreq = clone $this->dttLastRun;
            $dttLastRunPlusFreq->AddHours($this->emailFrequency);
        }
        $isFrequencySet = $this->emailFrequency > 0;
        $isCurrentDateEarlierThanLastRunPlusFrequency = $dttLastRunPlusFreq
            && $this->getCurrentDateGmtTime()->IsEarlierThan($dttLastRunPlusFreq);

        $isNotExpiredTimeout = !$blnFirstTime
            && $isCurrentDateEarlierThanLastRunPlusFrequency;

        if ($isNotExpiredTimeout
            || !$isFrequencySet
        ) {
            // if last run time plus frequency hours are bigger than current time
            log_warning($this->getName() . ' reminder: Missing sending reminder emails due to not matching frequency settings '
                . '(Timeout Not Expired: ' . (int)$isNotExpiredTimeout . ', Frequency Undefined: ' . (int)!$isFrequencySet . ')');
            return false;
        }
        return true;
    }

    /**
     * @return bool
     */
    public function checkLastRun()
    {
        $lastRun = $this->getLastRun();
        if (!$lastRun) {
            // if there is no last run timestamp and the interval is not set, stop processing
            log_warning('Missing LastRun timestamp or ScriptInterval to process' . $this->getName() . 'reminders');
            return false;
        }
        log_debug("Current time: " . $this->getCurrentDateGmtTime()->format('Y-m-d H:i:s') . ", Last run: $lastRun");

        return true;
    }

    /**
     * @param array $data
     * @return $this
     */
    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * @param string $templateName
     * @return $this
     */
    public function setTemplateName($templateName)
    {
        $this->templateName = $templateName;
        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        if (!$this->name) {
            $names = explode('_', $this->templateName);
            $this->name = !empty($names) ? strtolower($names[0]) : null;
        }
        return $this->name;
    }

    /**
     * @return string
     */
    public function getTemplateName()
    {
        return $this->templateName;
    }

    /**
     * @return int
     */
    public function getStatRemindedUsers()
    {
        return $this->statRemindedUsers;
    }

    /**
     * @param int $statRemindedUsers
     */
    public function setStatRemindedUsers($statRemindedUsers)
    {
        $this->statRemindedUsers = $statRemindedUsers;
    }

    /**
     * @return null|string
     */
    protected function getLastRun()
    {
        $lastRun = null;
        if (isset($this->dttLastRun)) {
            $lastRun = $this->getDateHelper()->convertSysToGmt($this->dttLastRun)->format('Y-m-d H:i:s');
        } elseif ($this->scriptInterval) {
            // if there is no last run timestamp and the interval is set,
            // use that to calculate "Last Run" time
            $dttLastRun = new QDateTime($this->getCurrentDateGmtTime());
            // subtract script interval hours
            $lastRun = $dttLastRun->AddHours(-$this->scriptInterval)->format('Y-m-d H:i:s');
        }
        return $lastRun;
    }

    /**
     * @param mixed $currentDateGmtTime
     * @return $this
     */
    public function setCurrentDateGmtTime($currentDateGmtTime)
    {
        $this->currentDateGmtTime = $currentDateGmtTime;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCurrentDateGmtTime()
    {
        return $this->currentDateGmtTime;
    }

}