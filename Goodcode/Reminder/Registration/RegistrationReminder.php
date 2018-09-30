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

namespace Sam\Reminder\Registration;

use Email_Template;
use Sam\Core\Date\CurrentDateTrait;
use Sam\Reminder\Base\ReminderBase;

class RegistrationReminder extends ReminderBase
{
    use CurrentDateTrait;
    /**
     * @var int
     */
    protected $statAuctions;

    /**
     * Get PaymentReminderQueue_Cron instance
     * @return self
     */
    public static function getInstance()
    {
        return parent::_getInstance(__CLASS__);
    }

    /**
     * Determine who needs to be reminded, create emails and drop in action queue
     *
     * @return boolean success
     */
    public function run()
    {
        $currentDateGmt = $this->getCurrentDateGmt();
        $this->setCurrentDateGmtTime($currentDateGmt);
        $this->setTemplateName(Email_Template::RegistrationReminder);
        $lastRun = $this->getLastRun();
        $interval = $this->getScriptInterval();
        $data = Loader::getInstance()->load($lastRun, $currentDateGmt->format('Y-m-d H:i:s'), $interval);
        $isProcessed = Processor::getInstance()
            ->setReminder($this)
            ->setData($data)
            ->process();
        return $isProcessed;
    }

    /**
     * @param int $statAuctions
     * @return $this
     */
    public function setStatAuctions($statAuctions)
    {
        $this->statAuctions = $statAuctions;
        return $this;
    }

    /**
     * @return int
     */
    public function getStatAuctions()
    {
        return $this->statAuctions;
    }
}