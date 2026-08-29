<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * A minted link. Holds the hash of the token, never the token.
 *
 * @property int $id
 * @property string $tokenHash
 * @property string $type
 * @property int|null $licenseId
 * @property int|null $downloadId
 * @property int|null $versionId
 * @property int|null $fileId
 * @property int|null $userId
 * @property string|null $email
 * @property int|null $orderId
 * @property int|null $maxUses
 * @property int $uses
 * @property string|null $ip
 * @property string $expiryDate
 */
class TokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TOKENS;
    }
}
