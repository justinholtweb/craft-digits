<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * A download's own columns, hanging off the element row.
 *
 * @property int $id
 * @property string|null $sku
 * @property string $accessType
 * @property string|null $groupIds
 * @property int|null $downloadLimit
 * @property int|null $accessDays
 * @property int|null $linkTtl
 * @property int|null $activationLimit
 * @property bool $issuesLicense
 * @property bool $gateUpdatesOnExpiry
 * @property bool $notifyOnRelease
 * @property int|null $sortOrder
 */
class DownloadRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DOWNLOADS;
    }
}
