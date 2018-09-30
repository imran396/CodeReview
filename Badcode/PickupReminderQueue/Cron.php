<?php
/**
 * Generate pickup reminder emails and drop into the action queue
 *
 * @property int ScriptInterval    time in hours
 * @property QDateTime LastRun     timestamp of when the script last ran
 * @property int StatRemindedUsers (read-only)
 */

use Sam\Core\Constants;
use Sam\Core\Date\CurrentDateTrait;
use Sam\Date\DateHelperAwareTrait;
use Sam\Invoice\Load\InvoiceLoaderAwareTrait;
use Sam\Settings\SettingsManagerAwareTrait;

class PickupReminderQueue_Cron extends Singleton
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
    /**
     * @var int
     */
    protected $statRemindedUsers = 0;

    /**
     * Get PickupReminderQueue_Cron instance
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
        $currentDateGmtIso = $dttCurrTimeGmt->format('Y-m-d H:i:s');
        $isFirstTime = false;
        $dttLastRunPlusFreq = null;

        if ($this->dttLastRun) {
            $dttLastRunPlusFreq = clone $this->dttLastRun;
            $dttLastRunPlusFreq->AddHours($systemParams->PickupReminderEmailFrequency);
        } else {
            $isFirstTime = true;
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

        log_debug("Current time: $currentDateGmtIso, Last run: $strLastRun");
        $isFrequencySet = $systemParams->PickupReminderEmailFrequency > 0;
        $isCurrentDateEarlierThanLastRunPlusFrequency = $dttLastRunPlusFreq
            && $dttCurrTimeGmt->IsEarlierThan($dttLastRunPlusFreq);
        $isNotExpiredTimeout = !$isFirstTime
            && $isCurrentDateEarlierThanLastRunPlusFrequency;
        if ($isNotExpiredTimeout
            || !$isFrequencySet
        ) {
            // if last run time plus frequency hours are bigger than current time
            log_warning('Pickup reminder: Missing sending reminder emails due to not matching frequency settings '
                . '(Timeout Not Expired: ' . (int)$isNotExpiredTimeout . ', Frequency Undefined: ' . (int)!$isFrequencySet . ')');
            return false;
        }
        $this->dttLastRun = $dttCurrTimeGmt;

        $n = "\n";
        $db = QApplication::$Database[1];

        try {
            // @formatter:off
            $queryParts = [
                "CREATE TEMPORARY TABLE pickup_reminders_tmp ( " . $n .
                "  `invoice_id` INT(10) NOT NULL PRIMARY KEY " . $n .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8 ",

                "REPLACE pickup_reminders_tmp (`invoice_id`) " . $n .
                "SELECT i.id AS invoice_id " . $n .
                "FROM invoice as i " .
                "WHERE i.invoice_status_id = " . Constants\Invoice::IS_PAID
            ];

            foreach ($queryParts as $query) {
                log_debug($query);
                $db->NonQuery($query);
            }

            // Query all not paid invoices
            // order by invoice.id
            $query =
                "SELECT prt.invoice_id as invoice_id, " .
                    "ii.id as invoice_item_id, " .
                    "li.id as lot_item_id, " .
                    "ali.id as auc_lot_item_id " . $n .

                "FROM pickup_reminders_tmp prt " . $n .

                "JOIN invoice_item ii ON ii.invoice_id = prt.invoice_id AND ii.active = 1 " . $n .
                "JOIN lot_item li ON ii.lot_item_id = li.id AND li.active = 1 " . $n .
                "JOIN auction_lot_item ali ON li.id = ali.lot_item_id " . $n .
                "JOIN invoice i ON i.id = prt.invoice_id " . $n .
                "JOIN `user` u ON u.id = i.bidder_id AND u.user_status_id = " . Constants\User::US_ACTIVE . " " . $n .
                "INNER JOIN account acc ON acc.id = li.account_id AND acc.active " . $n .

                "WHERE ali.lot_status_id != '" . Constants\Lot::LS_RECEIVED . "' " . $n .
                "GROUP BY invoice_id";
            // @formatter:on
            log_debug($query);
            $dbResult = $db->Query($query);

            while ($row = $dbResult->FetchArray(QDatabaseResultBase::FETCH_ASSOC)) {
                $invoiceId = $row['invoice_id'];
                $invoice = $this->getInvoiceLoader()->load($invoiceId);
                $user = $invoice->Bidder;
                if ($user->Email) {
                    $emailManager = new Email_Template(
                        $invoice->AccountId,
                        Email_Template::PickupReminder,
                        [$user, $invoice],
                        null
                    );
                    if ($emailManager->EmailTpl->Disabled) {
                        log_info('Invoice pickup reminder email is disabled '
                            . '(acc: ' . $invoice->AccountId . ', i: ' . $invoice->Id . ')');
                        continue;
                    }
                    $emailManager->addToActionQueue(ActionQueue::LOW);

                    // Update process stats
                    $this->statRemindedUsers++;
                    log_info('Created pickup reminder for user ' . $user->Username
                        . ' (u: ' . $user->Id . ', ' . $user->Email . ')'
                        . ' for invoice# ' . $invoice->InvoiceNo
                        . ' (i: ' . $invoice->Id . ', acc: ' . $invoice->AccountId . ')');
                } else {
                    log_info('Ignoring user ' . $user->Username . ' (u: ' . $user->Id . ')'
                        . ' for invoice# ' . $invoice->InvoiceNo
                        . ' (i: ' . $invoice->Id . ', acc: ' . $invoice->AccountId . '),'
                        . ' because of missing email');
                }
            }

            return true;
        } catch (Exception $e) {
            log_error('Error while processing pickup reminders: ' . $e->getMessage());
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
