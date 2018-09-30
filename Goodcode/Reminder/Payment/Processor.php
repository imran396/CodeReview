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

namespace Sam\Reminder\Payment;

use ActionQueue;
use Email_Template;
use Exception;
use Sam\Core\Date\CurrentDateTrait;
use Sam\Invoice\Load\InvoiceLoaderAwareTrait;
use Sam\Reminder\Base\ReminderBase;

class Processor extends \CustomizableClass
{
    use CurrentDateTrait;
    use InvoiceLoaderAwareTrait;
    /**
     * @var array
     */
    public $data;

    /**
     * @var ReminderBase
     */
    protected $reminder;

    /**
     * @var int
     */
    protected $counter;

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
        $this->reminder->setStatRemindedUsers(0);
        $this->counter = 0;
        if (!$this->reminder->checkLastRun()) {
            $isProcessed = false;
        } else if (!$this->reminder->checkNotExpiredTimeoutOrFrequencySet()) {
            $isProcessed = false;
        } else {
            $this->reminder->setDttLastRun($this->reminder->getCurrentDateGmtTime());
            try {
                foreach ($this->data as $invoiceId) {
                    $invoice = $this->getInvoiceLoader()->load($invoiceId);
                    $isCreated = $this->createReminder($invoice);
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
     * @param $invoice
     * @param $user
     * @throws \QCallerException
     */
    protected function createReminder($invoice)
    {
        if (!$invoice->Bidder->Email) {
            log_info('Ignoring user ' . $invoice->Bidder->Username . ' (u: ' . $invoice->Bidder->Id . ')'
                . ' for Invoice# ' . $invoice->InvoiceNo
                . ' (i: ' . $invoice->Id . ', acc: ' . $invoice->AccountId . '),'
                . ' because of missing email');
            return false;
        }
        $emailManager = new Email_Template(
            $invoice->AccountId,
            $this->reminder->getTemplateName(),
            [$invoice->Bidder, $invoice],
            null
        );
        if ($emailManager->EmailTpl->Disabled) {
            log_info("Invoice " . $this->reminder->getName() . " reminder email is disabled "
                . '(acc: ' . $invoice->AccountId . ', i: ' . $invoice->Id . ')');
            $isCreated = false;
        } else {
            $emailManager->addToActionQueue(ActionQueue::LOW);
            // Update process stats
            $this->counter++;
            $this->reminder->setStatRemindedUsers($this->counter);
            log_info("Created" . $this->reminder->getName() . " reminder for user " . $invoice->Bidder->Username
                . ' (u: ' . $invoice->Bidder->Id . ', ' . $invoice->Bidder->Email . ')'
                . ' for Invoice# ' . $invoice->InvoiceNo
                . ' (i: ' . $invoice->Id . ', acc: ' . $invoice->AccountId . ')');
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
     * @param ReminderBase $reminder
     * @return $this
     */
    public function setReminder($reminder)
    {
        $this->reminder = $reminder;
        return $this;
    }
}