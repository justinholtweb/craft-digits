<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use craft\base\Model;
use DateTime;

/**
 * A minted link, as it is stored.
 *
 * The token itself is only ever in memory — {@see \justinholtweb\digits\services\Links::mint()}
 * returns it once, and what the row keeps is its SHA-256. A stolen database backup is therefore a
 * list of hashes, and a lookup is one indexed equality test rather than a scan through candidate
 * rows.
 */
class LinkToken extends Model
{
    public const TYPE_DOWNLOAD = 'download';
    public const TYPE_PORTAL = 'portal';

    public ?int $id = null;
    public string $tokenHash = '';
    public string $type = self::TYPE_DOWNLOAD;
    public ?int $licenseId = null;
    public ?int $downloadId = null;
    public ?int $versionId = null;
    public ?int $fileId = null;
    public ?int $userId = null;
    public ?string $email = null;
    public ?int $orderId = null;
    public ?int $maxUses = null;
    public int $uses = 0;
    public ?string $ip = null;
    public ?DateTime $expiryDate = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** Only ever set on the instance that was just minted. Never read back from the database. */
    public ?string $token = null;

    /**
     * Columns Craft has to hydrate as dates rather than as strings.
     *
     * Missing one is not a type mismatch you notice — the model loads fine and every date
     * comparison against it silently becomes a string comparison.
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['expiryDate']);
    }

    public function getIsExpired(): bool
    {
        return $this->expiryDate !== null && $this->expiryDate->getTimestamp() <= time();
    }

    public function getIsSpent(): bool
    {
        return $this->maxUses !== null && $this->maxUses > 0 && $this->uses >= $this->maxUses;
    }

    public function getUsesRemaining(): ?int
    {
        if ($this->maxUses === null || $this->maxUses === 0) {
            return null;
        }

        return max(0, $this->maxUses - $this->uses);
    }
}
