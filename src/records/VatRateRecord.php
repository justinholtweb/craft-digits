<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * A country's VAT rate, valid between two dates.
 *
 * @property int $id
 * @property string $countryCode
 * @property string|null $name
 * @property string $rate
 * @property string $kind
 * @property string|null $effectiveFrom
 * @property string|null $effectiveTo
 */
class VatRateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::VATRATES;
    }
}
