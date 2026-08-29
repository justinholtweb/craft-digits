<?php

declare(strict_types=1);

namespace justinholtweb\digits\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\digits\db\Table;

/**
 * @method \justinholtweb\digits\elements\Download[] all($db = null)
 * @method \justinholtweb\digits\elements\Download|null one($db = null)
 * @method \justinholtweb\digits\elements\Download|null nth(int $n, $db = null)
 */
class DownloadQuery extends ElementQuery
{
    public mixed $sku = null;
    public mixed $accessType = null;
    public ?bool $issuesLicense = null;
    /** Downloads a given licence unlocks. */
    public mixed $licenseId = null;

    protected array $defaultOrderBy = ['digits_downloads.sortOrder' => SORT_ASC];

    public function sku(mixed $value): self
    {
        $this->sku = $value;

        return $this;
    }

    public function accessType(mixed $value): self
    {
        $this->accessType = $value;

        return $this;
    }

    public function issuesLicense(?bool $value = true): self
    {
        $this->issuesLicense = $value;

        return $this;
    }

    public function licenseId(mixed $value): self
    {
        $this->licenseId = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('digits_downloads');

        $this->query->select([
            'digits_downloads.sku',
            'digits_downloads.accessType',
            'digits_downloads.groupIds',
            'digits_downloads.downloadLimit',
            'digits_downloads.accessDays',
            'digits_downloads.linkTtl',
            'digits_downloads.activationLimit',
            'digits_downloads.issuesLicense',
            'digits_downloads.gateUpdatesOnExpiry',
            'digits_downloads.notifyOnRelease',
            'digits_downloads.sortOrder',
        ]);

        if ($this->sku !== null) {
            $this->subQuery->andWhere(Db::parseParam('digits_downloads.sku', $this->sku));
        }

        if ($this->accessType !== null) {
            $this->subQuery->andWhere(Db::parseParam('digits_downloads.accessType', $this->accessType));
        }

        if ($this->issuesLicense !== null) {
            $this->subQuery->andWhere(['digits_downloads.issuesLicense' => $this->issuesLicense]);
        }

        // Filtered in the subquery, not the outer one: the outer query is the hydration pass and
        // a `where` on it is applied after the limit has already thrown rows away.
        if ($this->licenseId !== null) {
            $this->subQuery->andWhere(['digits_downloads.id' => (new Query())
                ->select(['downloadId'])
                ->from([Table::LICENSE_DOWNLOADS])
                ->where(Db::parseParam('licenseId', $this->licenseId)),
            ]);
        }

        return true;
    }
}
