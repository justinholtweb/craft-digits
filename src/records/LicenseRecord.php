<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * A licence's own columns, hanging off the element row.
 *
 * @property int $id
 * @property string $licenseKey
 * @property int|null $ownerId
 * @property string $email
 * @property string|null $customerName
 * @property int|null $orderId
 * @property int|null $lineItemId
 * @property string|null $orderReference
 * @property string $status
 * @property string $source
 * @property string $issuedDate
 * @property string|null $expiryDate
 * @property int|null $downloadLimit
 * @property int $downloadCount
 * @property int|null $activationLimit
 * @property int $activationCount
 * @property string|null $revokedDate
 * @property string|null $revokedReason
 * @property string|null $notes
 */
class LicenseRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LICENSES;
    }
}
