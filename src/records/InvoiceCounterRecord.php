<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * One row per series per reset period. This is why 'restart every year' means anything.
 *
 * @property int $id
 * @property string $series
 * @property string $periodKey
 * @property int $counter
 */
class InvoiceCounterRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::INVOICE_COUNTERS;
    }
}
