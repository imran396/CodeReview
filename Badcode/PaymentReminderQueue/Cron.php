<?php
/**
 * Generate payment reminder emails and drop into the action queue
 *
 * @property int ScriptInterval    time in hours
 * @property QDateTime LastRun     timestamp of when the script last ran
 * @property int StatAuctions      (read-only)
 * @property int StatRemindedUsers (read-only)
 */

use Sam\Core\Constants;
use Sam\Core\Date\CurrentDateTrait;
use Sam\Date\DateHelperAwareTrait;
use Sam\Invoice\Load\InvoiceLoaderAwareTrait;
use Sam\Settings\SettingsManagerAwareTrait;

class PaymentReminderQueue_Cron extends Singleton
{
    use CurrentDateTrait;
    use DateHelperAwareTrait;
    use InvoiceLoaderAwareTrait;
    use SettingsManagerAwareTrait;

    protected $scriptInterval;
    /**
     * @var QDateTime
     */
    protected $dttLastRun;
    protected $statRemindedUsers = 0;

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
        $dttCurrTimeGmt = $this->getCurrentQDateGmt();
        $systemParams = $this->getSettingsManager()->loadForMainAccount();
        $strCurrTimeGmt = $dttCurrTimeGmt->format('Y-m-d H:i:s');
        $blnFirstTime = false;
        $dttLastRunPlusFreq = null;

        if ($this->dttLastRun) {
            $dttLastRunPlusFreq = clone $this->dttLastRun;
            $dttLastRunPlusFreq->AddHours($systemParams->PickupReminderEmailFrequency);
        } else {
            $blnFirstTime = true;
        }

        $this->statRemindedUsers = 0;
        $strLastRun = null;
        if (isset($this->dttLastRun)) {
            $strLastRun = $this->getDateHelper()->convertSysToGmt($this->dttLastRun)->format('Y-m-d H:i:s');
        } elseif ($this->scriptInterval) {
            // if there is no last run timestamp and the interval is set,
            // use that to calculate "Last Run" time
            $dttLastRun = new QDateTime($dttCurrTimeGmt);
            // subtract script interval hours
            $strLastRun = $dttLastRun->AddHours(-$this->scriptInterval)->format('Y-m-d H:i:s');
        }

        if (!$strLastRun) {
            // if there is no last run timestamp and the interval is not set, stop processing
            log_warning('Missing LastRun timestamp or ScriptInterval to process registration reminders');
            return false;
        }

        log_debug("Current time: $strCurrTimeGmt, Last run: $strLastRun");
        $isFrequencySet = $systemParams->PaymentReminderEmailFrequency > 0;
        $isCurrentDateEarlierThanLastRunPlusFrequency = $dttLastRunPlusFreq
            && $dttCurrTimeGmt->IsEarlierThan($dttLastRunPlusFreq);
        $isNotExpiredTimeout = !$blnFirstTime
            && $isCurrentDateEarlierThanLastRunPlusFrequency;
        if ($isNotExpiredTimeout
            || !$isFrequencySet
        ) {
            // if last run time plus frequency hours are bigger than current time
            log_warning('Payment reminder: Missing sending reminder emails due to not matching frequency settings '
                . '(Timeout Not Expired: ' . (int)$isNotExpiredTimeout . ', Frequency Undefined: ' . (int)!$isFrequencySet . ')');
            return false;
        }
        $this->dttLastRun = $dttCurrTimeGmt;

        $n = "\n";
        $db = QApplication::$Database[1];

        try {
            // @formatter:off
            $queryParts = [
                "CREATE TEMPORARY TABLE payment_reminders_tmp ( " . $n .
                "  `invoice_id` INT(10) NOT NULL PRIMARY KEY, " . $n .
                "  `bid_total` DECIMAL(12, 2), " . $n .
                "  `premium` DECIMAL(12, 2), " . $n .
                "  `tax` DECIMAL(12, 2) ," . $n .
                "  `shipping_fees` DECIMAL(12, 2), " . $n .
                "  `extra_charges` DECIMAL(12, 2), " . $n .
                "  `total_payment` DECIMAL(12, 2), " . $n .
                "  `cash_discount` DECIMAL(12, 2), " . $n .
                "  `total` DECIMAL(12, 2), " . $n .
                "  `balance` DECIMAL(12, 2) " . $n .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8 " ,

                "REPLACE payment_reminders_tmp (`invoice_id`, `bid_total`, `premium`, `tax`, `shipping_fees`, `extra_charges`, `total_payment`, `cash_discount`, `total`, `balance`) " . $n .
                "SELECT i.id AS invoice_id, " . $n .
                    "@bid_total := ROUND(i.bid_total, 2) AS bid_total, " . $n .
                    "@premium := ROUND(i.buyers_premium, 2) AS premium, " . $n .
                    "@tax := ROUND(i.tax, 2) AS tax, " . $n .
                    "@shipping_fees := IFNULL(ROUND(i.shipping_fees, 2), 0) AS shipping_fees, " . $n .
                    "@extra_charges := IFNULL(ROUND(i.extra_charges, 2), 0) AS extra_charges, " . $n .
                    "@total_payment := IFNULL(ROUND(i.total_payment, 2), 0) AS total_payment, " . $n .
                    "@cash_discount := IF(i.cash_discount = true, (@bid_total + @premium) * (select IFNULL(cash_discount,0) from auction_parameters where account_id = i.account_id) / 100, 0) AS cash_discount, " . $n .
                    "@total := CAST((@bid_total + @premium + @tax + @shipping_fees + @extra_charges) - @cash_discount AS DECIMAL(12,2)) AS total, " . $n .
                    "@balance := CAST((@total  - @total_payment) AS DECIMAL(12,2)) AS balance " . $n .

                "FROM invoice as i " . $n .
                "JOIN `user` u ON u.id = i.bidder_id AND u.user_status_id = " . Constants\User::US_ACTIVE . " " . $n .
                "INNER JOIN account acc ON acc.id = i.account_id AND acc.active " . $n .
                "WHERE i.invoice_status_id = " . Constants\Invoice::IS_PENDING
            ];
            // @formatter:on

            foreach ($queryParts as $query) {
                log_debug($query);
                $db->NonQuery($query);
            }

            // Query all not paid invoices
            // order by invoice.id
            $query = "SELECT * FROM payment_reminders_tmp WHERE balance > 0";
            log_debug($query);
            $dbResult = $db->Query($query);

            while ($row = $dbResult->FetchArray(QDatabaseResultBase::FETCH_ASSOC)) {
                $invoiceId = $row['invoice_id'];
                $invoice = $this->getInvoiceLoader()->load($invoiceId);
                $user = $invoice->Bidder;
                if ($user->Email) {
                    $emailManager = new Email_Template(
                        $invoice->AccountId,
                        Email_Template::PaymentReminder,
                        [$user, $invoice],
                        null
                    );
                    if ($emailManager->EmailTpl->Disabled) {
                        log_info('Invoice payment reminder email is disabled '
                            . '(acc: ' . $invoice->AccountId . ', i: ' . $invoice->Id . ')');
                        continue;
                    }
                    $emailManager->addToActionQueue(ActionQueue::LOW);

                    // Update process stats
                    $this->statRemindedUsers++;
                    log_info('Created payment reminder for user ' . $user->Username
                        . ' (u: ' . $user->Id . ', ' . $user->Email . ')'
                        . ' for Invoice# ' . $invoice->InvoiceNo
                        . ' (i: ' . $invoice->Id . ', acc: ' . $invoice->AccountId . ')');
                } else {
                    log_info('Ignoring user ' . $user->Username . ' (u: ' . $user->Id . ')'
                        . ' for Invoice# ' . $invoice->InvoiceNo
                        . ' (i: ' . $invoice->Id . ', acc: ' . $invoice->AccountId . '),'
                        . ' because of missing email');
                }
            }
            return true;
        } catch (Exception $e) {
            log_error('Error while processing payment reminders: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Override method to perform a property "Set"
     * This will set the property $strName to be $mixValue
     *
     * @param string $name Name of the property to set
     * @param string $mixValue New value of the property
     * @return mixed
     * @throws QCallerException
     * @throws QUndefinedPropertyException
     */
    public function __set($name, $mixValue)
    {
        switch ($name) {
            case 'LastRun':
                // Sets the value for _dttLastRun
                // @param DateTime $mixValue
                // @return integer
                try {
                    return ($this->dttLastRun = QType::Cast($mixValue, QType::DateTime));
                } catch (QCallerException $e) {
                    $e->IncrementOffset();
                    throw $e;
                }
            case 'ScriptInterval':
                // Sets the value for _intScriptInterval
                // @param integer $mixValue
                // @return integer
                try {
                    return ($this->scriptInterval = QType::Cast($mixValue, QType::Integer));
                } catch (QCallerException $e) {
                    $e->IncrementOffset();
                    throw $e;
                }
            default:
                $reflection = new ReflectionClass($this);
                throw new QUndefinedPropertyException("SET", $reflection->getName(), $name);
        }
    }

    /**
     * Override method to perform a property "Get"
     * This will get the value of $name
     *
     * @param string $name Name of the property to get
     * @return mixed
     * @throws QUndefinedPropertyException
     */
    public function __get($name)
    {
        switch ($name) {
            case 'LastRun':
                return $this->dttLastRun;
            case 'ScriptInterval':
                return $this->scriptInterval;
            case 'StatRemindedUsers':
                return $this->statRemindedUsers;
            default:
                $reflection = new ReflectionClass($this);
                throw new QUndefinedPropertyException("GET", $reflection->getName(), $name);
        }
    }

}
