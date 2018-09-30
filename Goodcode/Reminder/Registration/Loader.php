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

use MySearch_Query;
use QApplication;
use QDatabaseResultBase;
use Sam\Core\Constants;

class Loader extends \CustomizableClass
{

    /**
     * Class instantiated method
     * @return $this
     */
    public static function getInstance()
    {
        return parent::_getInstance(__CLASS__);
    }

    public function load($strLastRun, $strCurrTimeGmt, $scriptInterval)
    {
        /** @var \QDatabaseBase $db */
        $db = QApplication::$Database[1];
        $mySearchQueryManager = MySearch_Query::getInstance();
        $n = "\n";
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
                    "AND a.auction_type IN ('" . Constants\Auction::LIVE . "', '" . Constants\Auction::HYBRID . "')" .
                    "AND IF(ap.reg_reminder_email > " . $scriptInterval . ", " .
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
                    "IF(ap.reg_reminder_email > " . $scriptInterval . ", " .
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
        $rows = [];
        $i = 0;
        while ($row = $dbResult->FetchArray(QDatabaseResultBase::FETCH_ASSOC)) {
            $rows[$i]['auc_id'] = $row['auc_id'];
            $rows[$i]['user_id'] = $row['user_id'];
            $i++;
        }
        return $rows;
    }
}