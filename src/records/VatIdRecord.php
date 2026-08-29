<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * The cached result of checking a VAT identification number, and where the answer came from.
 *
 * @property int $id
 * @property string $vatId
 * @property string $countryCode
 * @property bool|null $valid
 * @property string|null $name
 * @property string|null $address
 * @property string $source
 * @property string|null $consultationNumber
 * @property string $dateChecked
 */
class VatIdRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::VATIDS;
    }
}
