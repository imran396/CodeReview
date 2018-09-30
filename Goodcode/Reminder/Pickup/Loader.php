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

namespace Sam\Reminder\Pickup;

use QApplication;
use QDatabaseResultBase;
use Sam\Core\Constants;

class Loader extends \CustomizableClass
{
    /**
     * Class instantiation method
     * @return $this
     */
    public static function getInstance()
    {
        return parent::_getInstance(__CLASS__);
    }

    /**
     * @return array
     */
    public function load()
    {
        $database = QApplication::$Database[1];
        $this->createTemporaryTable($database);
        $invoiceIds = [];
        $n = "\n";
        // Query all not paid invoices
        // order by invoice.id
        // @formatter:off
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
        $dbResult = $database->Query($query);

        while ($row = $dbResult->FetchArray(QDatabaseResultBase::FETCH_ASSOC)) {
            $invoiceIds[] = $row['invoice_id'];
        }
        return $invoiceIds;
    }

    public function createTemporaryTable($databases)
    {
        $n = "\n";
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
                $databases->NonQuery($query);
            }
            // @formatter:off
    }
}