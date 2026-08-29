<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * One release of a download.
 *
 * @property int $id
 * @property int $downloadId
 * @property string $version
 * @property string|null $releaseNotes
 * @property bool $isCurrent
 * @property string $status
 * @property string|null $dateReleased
 * @property string|null $dateNotified
 * @property int|null $sortOrder
 */
class VersionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::VERSIONS;
    }
}
