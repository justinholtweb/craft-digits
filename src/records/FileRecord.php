<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * One file inside a version — an asset, or a URL.
 *
 * @property int $id
 * @property int $versionId
 * @property string $name
 * @property int|null $assetId
 * @property string|null $url
 * @property string|null $filename
 * @property int|null $size
 * @property int|null $sortOrder
 */
class FileRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::FILES;
    }
}
