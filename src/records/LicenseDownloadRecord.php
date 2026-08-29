<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * What a licence unlocks. The only answer to that question — there is deliberately no column beside it.
 *
 * @property int $id
 * @property int $licenseId
 * @property int $downloadId
 * @property int|null $sortOrder
 */
class LicenseDownloadRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LICENSE_DOWNLOADS;
    }
}
