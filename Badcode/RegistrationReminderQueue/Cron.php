<?php
/**
 * Generate auction registration reminder emails and drop into the action queue
 *
 * @property int ScriptInterval    time in hours
 * @property QDateTime LastRun     timestamp of when the script last ran
 * @property int StatAuctions      (read-only)
 * @property int StatRemindedUsers (read-only)
 */

use Sam\Auction\Load\AuctionLoaderAwareTrait;
use Sam\Auction\Render\AuctionRendererAwareTrait;
use Sam\Core\Constants;
use Sam\Core\Date\CurrentDateTrait;
use Sam\User\Load\UserLoaderAwareTrait;

class RegistrationReminderQueue_Cron extends Singleton
{
    use AuctionLoaderAwareTrait;
    use AuctionRendererAwareTrait;
    use CurrentDateTrait;
    use UserLoaderAwareTrait;

    protected $scriptInterval;
    protected $dttLastRun;
    protected $statAuctions = 0;
    protected $statRemindedUsers = 0;

    /**
     * Get RegistrationReminderQueue_Cron instance
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
        $n = "\n";
        $dttCurrTimeGmt = $this->getCurrentQDateGmt();
        $strCurrTimeGmt = $dttCurrTimeGmt->format('Y-m-d H:i:s');
        $this->statAuctions = 0;
        $this->statRemindedUsers = 0;

        $strLastRun = null;
        if (isset($this->dttLastRun)) {
            $strLastRun = $this->dttLastRun->format('Y-m-d H:i:s');
        } elseif ($this->scriptInterval) {
            // if there is no last run timestamp and the interval is set,
            // use that to calculate "Last Run" time
            $dttLastRun = new QDateTime($dttCurrTimeGmt);
            // subtract script interval hours
            $strLastRun = $dttLastRun->AddHours(-$this->scriptInterval)->format('Y-m-d H:i:s');
        }
        $this->dttLastRun = $dttCurrTimeGmt;

        if (!$strLastRun) {
            // if there is no last run timestamp and the interval is not set, stop processing
            log_warning('Missing LastRun timestamp or ScriptInterval to process registration reminders');
            return false;
        }
        log_debug("Current time: $strCurrTimeGmt, Last run: $strLastRun");

        $db = QApplication::$Database[1];
        $mySearchQueryManager = MySearch_Query::getInstance();

        try {
            // @formatter:off
            $query =
                // create temporary table for auctions
                "CREATE TEMPORARY TABLE auction_reminders_tmp ( " .
                    "auc_id INT(10) NOT NULL PRIMARY KEY, " .
                    "reg_reminder INT(10) " .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8;" . $n .

                // live auctions where reminder needs to be sent between last run and now
                "REPLACE auction_reminders_tmp (auc_id, reg_reminder) " .
                "SELECT " .
                    "a.id AS auc_id, " .
                    "ap.reg_reminder_email " .
                "FROM auction a " .
                "INNER JOIN auction_parameters ap ON ap.account_id = a.account_id " .
                "INNER JOIN `account` acc ON acc.id = a.account_id AND acc.active " .
                "WHERE " .
                    "a.auction_status_id IN (" . implode(',', Constants\Auction::$openAuctionStatuses) . ") " .
                    "AND a.auction_type IN ('" . Constants\Auction::LIVE . "', '" . Constants\Auction::HYBRID . "') " .
                    "AND IF(ap.reg_reminder_email > " . $this->scriptInterval . ", " .
                        "a.start_date_gmt BETWEEN DATE_ADD(" . $db->SqlVariable($strLastRun) .", INTERVAL ap.reg_reminder_email HOUR) " .
                            "AND DATE_ADD(" . $db->SqlVariable($strCurrTimeGmt) . ", INTERVAL ap.reg_reminder_email HOUR), " .
                        "a.start_date_gmt BETWEEN " . $db->SqlVariable($strCurrTimeGmt) . " " .
                            "AND DATE_ADD(" . $db->SqlVariable($strCurrTimeGmt) . ", INTERVAL ap.reg_reminder_email HOUR)); " . $n .

                // timed auctions where reminder needs to be sent between last run and now
                "REPLACE auction_reminders_tmp (auc_id, reg_reminder) " .
                "SELECT " .
                    "a.id AS auc_id, " .
                    "ap.reg_reminder_email " .
                "FROM auction a " .
                "INNER JOIN auction_cache ac ON ac.auction_id=a.id " .
                "INNER JOIN auction_lot_item ali ON ali.auction_id=a.id AND ali.lot_status_id IN (".implode(',',Constants\Lot::$inAuctionStatuses).") " .
                "INNER JOIN auction_lot_item_cache alic ON alic.auction_lot_item_id=ali.id " .
                "INNER JOIN auction_parameters ap ON ap.account_id = a.account_id " .
                "INNER JOIN `account` acc ON acc.id = a.account_id AND acc.active " .
                "WHERE " .
                    "a.auction_status_id IN (" . implode(',', Constants\Auction::$openAuctionStatuses) . ") " .
                    "AND a.auction_type='" . Constants\Auction::TIMED . "' " .
                "GROUP BY a.id " .
                "HAVING " .
                    "IF(ap.reg_reminder_email > " . $this->scriptInterval . ", " .
                        "MIN(" . $mySearchQueryManager->getTimedLotEndDateGmtExpr() . ") " .
                            "BETWEEN DATE_ADD(" . $db->SqlVariable($strLastRun) . ", INTERVAL ap.reg_reminder_email HOUR) " .
                            "AND DATE_ADD(" . $db->SqlVariable($strCurrTimeGmt) . ", INTERVAL ap.reg_reminder_email HOUR), " .
                        "MIN(" . $mySearchQueryManager->getTimedLotEndDateGmtExpr() . ") " .
                            "BETWEEN " . $db->SqlVariable($strCurrTimeGmt) . " " .
                            "AND DATE_ADD(" . $db->SqlVariable($strCurrTimeGmt) . ", INTERVAL ap.reg_reminder_email HOUR)); " . $n .
                "SELECT a.auc_id, ab.user_id AS user_id " .
                "FROM auction_reminders_tmp a " .
                "INNER JOIN auction_bidder ab ON ab.auction_id=a.auc_id " .
                "INNER JOIN `user` u ON u.id = ab.user_id " .
                "WHERE u.user_status_id = " . Constants\User::US_ACTIVE . " " .
                    "AND u.flag NOT IN (" . $db->SqlVariable(Constants\User::FLAG_NOAUCTIONAPPROVAL) . ", " . $db->SqlVariable(Constants\User::FLAG_BLOCK) . ") " .
                "ORDER BY a.auc_id;";
            // @formatter:on

            $intOldAuctionId = null;
            // Query all registered users for these auctions, exclude flagged users
            // order by auction.id for improved Auction object caching
            log_debug($query);

            $arrResult = $db->MultiQuery($query);
            $dbResult = current($arrResult);
            while ($row = $dbResult->FetchArray(QDatabaseResultBase::FETCH_ASSOC)) {
                $auctionId = $row['auc_id'];
                $auction = $this->getAuctionLoader()->load($row['auc_id']);
                $user = $this->getUserLoader()->load($row['user_id']);
                if ($user->Email) {
                    $emailManager = new Email_Template(
                        $auction->AccountId,
                        Email_Template::RegistrationReminder,
                        [$user, $auction],
                        $auction->Id
                    );
                    $emailManager->addToActionQueue(ActionQueue::LOW);

                    // Update process stats
                    if ($intOldAuctionId != $auctionId) {
                        $this->statAuctions++;
                    }
                    $intOldAuctionId = $auctionId;
                    $this->statRemindedUsers++;
                    $saleNo = $this->getAuctionRenderer()->renderSaleNo($auction);
                    log_info('Created registration reminder for user ' . $user->Username
                        . ' (u: ' . $user->Id . ', ' . $user->Email . ')'
                        . ' for auction# ' . $saleNo
                        . ' (a: ' . $auction->Id . ', acc: ' . $auction->AccountId . ')');
                } else {
                    $saleNo = $this->getAuctionRenderer()->renderSaleNo($auction);
                    log_info('Ignoring user ' . $user->Username . ' (u: ' . $user->Id . ')'
                        . ' for auction# ' . $saleNo
                        . ' (a:' . $auction->Id . ', acc: ' . $auction->AccountId . '),'
                        . ' because of missing email');
                }
            }

            return true;
        } catch (Exception $e) {
            log_error('Error while processing registration reminders: ' . $e->getMessage());
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
            case 'StatAuctions':
                return $this->statAuctions;
            case 'StatRemindedUsers':
                return $this->statRemindedUsers;
            default:
                $reflection = new ReflectionClass($this);
                throw new QUndefinedPropertyException("GET", $reflection->getName(), $name);
        }
    }

}
