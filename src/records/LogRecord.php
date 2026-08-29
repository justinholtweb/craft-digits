<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * One line of the append-only download log.
 *
 * @property int $id
 * @property string $event
 * @property int|null $licenseId
 * @property int|null $downloadId
 * @property int|null $versionId
 * @property int|null $fileId
 * @property int|null $tokenId
 * @property int|null $userId
 * @property string|null $email
 * @property string|null $reason
 * @property string|null $detail
 * @property string|null $ip
 * @property string|null $userAgent
 * @property int|null $bytes
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
