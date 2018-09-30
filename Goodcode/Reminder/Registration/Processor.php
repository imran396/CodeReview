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

use ActionQueue;
use Email_Template;
use Exception;
use Sam\Auction\Load\AuctionLoaderAwareTrait;
use Sam\Auction\Render\AuctionRendererAwareTrait;
use Sam\User\Load\UserLoaderAwareTrait;

class Processor extends \CustomizableClass
{
    use AuctionLoaderAwareTrait;
    use AuctionRendererAwareTrait;
    use UserLoaderAwareTrait;

    /**
     * @var array
     */
    public $data;

    /**
     * @var RegistrationReminder
     */
    protected $reminder;

    /**
     * @var int
     */
    protected $reminderUserCounter;

    /**
     * @var int
     */
    protected $reminderAuctionCounter;
    /**
     * @var int
     */
    protected $oldAuctionId;

    /**
     * @return $this
     */
    public static function getInstance()
    {
        $instance = parent::_getInstance(__CLASS__);
        return $instance;
    }

    public function process()
    {
        $this->oldAuctionId = null;
        $this->reminder->setStatRemindedUsers(0);
        $this->reminder->setStatAuctions(0);
        $this->reminderUserCounter = 0;
        $this->reminderAuctionCounter = 0;
        if (!$this->reminder->checkLastRun()) {
            $isProcessed = false;
        } else {
            $this->reminder->setDttLastRun($this->reminder->getCurrentDateGmtTime());
            try {
                foreach ($this->data as $key => $row) {
                    $auction = $this->getAuctionLoader()->load($row['auc_id']);
                    $user = $this->getUserLoader()->load($row['user_id']);
                    $isCreated = $this->createReminder($auction, $user);
                    if (!$isCreated) {
                        continue;
                    }
                }
            } catch (Exception $e) {
                log_error("Error while processing " . $this->reminder->getName() . " reminders: " . $e->getMessage());
            }
            $isProcessed = true;
        }
        return $isProcessed;
    }

    /**
     * @param $auction
     * @param $user
     * @return bool
     * @throws \QCallerException
     */
    protected function createReminder($auction, $user)
    {
        if (!$user->Email) {
            $saleNo = $this->getAuctionRenderer()->renderSaleNo($auction);
            log_info('Ignoring user ' . $user->Username . ' (u: ' . $user->Id . ')'
                . ' for auction# ' . $saleNo
                . ' (a:' . $auction->Id . ', acc: ' . $auction->AccountId . '),'
                . ' because of missing email');
            return false;
        }
        $emailManager = new Email_Template(
            $auction->AccountId,
            $this->reminder->getTemplateName(),
            [$user, $auction],
            $auction->Id
        );
        if ($emailManager->EmailTpl->Disabled) {
            log_info($this->reminder->getName() . " reminder email is disabled "
                . '(acc: ' . $auction->AccountId . ', i: ' . $auction->Id . ')');
            $isCreated = false;
        } else {
            $emailManager->addToActionQueue(ActionQueue::LOW);
            // Update process stats
            $this->reminderUserCounter++;
            $this->reminder->setStatRemindedUsers($this->reminderUserCounter);

            if ($this->oldAuctionId != $auction->Id) {
                $this->reminderAuctionCounter++;
            }
            $this->reminder->setStatAuctions($this->reminderAuctionCounter);
            $this->oldAuctionId = $auction->Id;
            $saleNo = $this->getAuctionRenderer()->renderSaleNo($auction);
            log_info('Created registration reminder for user ' . $user->Username
                . ' (u: ' . $user->Id . ', ' . $user->Email . ')'
                . ' for auction# ' . $saleNo
                . ' (a: ' . $auction->Id . ', acc: ' . $auction->AccountId . ')');
            $isCreated = true;
        }
        return $isCreated;
    }

    /**
     * @param array $data
     * @return Processor
     */
    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param RegistrationReminder $reminder
     * @return Processor
     */
    public function setReminder($reminder)
    {
        $this->reminder = $reminder;
        return $this;
    }

    /**
     * @return RegistrationReminder
     */
    public function getReminder()
    {
        return $this->reminder;
    }

    /**
     * @param int $reminderUserCounter
     * @return Processor
     */
    public function setReminderUserCounter($reminderUserCounter)
    {
        $this->reminderUserCounter = $reminderUserCounter;
        return $this;
    }

    /**
     * @return int
     */
    public function getReminderUserCounter()
    {
        return $this->reminderUserCounter;
    }

    /**
     * @return int
     */
    public function getReminderAuctionCounter()
    {
        return $this->reminderAuctionCounter;
    }

    /**
     * @param int $reminderAuctionCounter
     * @return Processor
     */
    public function setReminderAuctionCounter($reminderAuctionCounter)
    {
        $this->reminderAuctionCounter = $reminderAuctionCounter;
        return $this;
    }
}