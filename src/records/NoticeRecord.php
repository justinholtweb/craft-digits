<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * Proof that a release notice went to a licensee, so a re-run cannot mail them twice.
 *
 * @property int $id
 * @property int $versionId
 * @property int $licenseId
 * @property string $email
 * @property string $status
 * @property string|null $detail
 */
class NoticeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::NOTICES;
    }
}
