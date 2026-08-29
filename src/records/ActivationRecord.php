<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * One seat on a licence.
 *
 * @property int $id
 * @property int $licenseId
 * @property string $instance
 * @property string|null $label
 * @property string $status
 * @property string|null $ip
 * @property string|null $userAgent
 * @property string $dateActivated
 * @property string|null $dateDeactivated
 * @property string|null $dateLastSeen
 */
class ActivationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ACTIVATIONS;
    }
}
