<?php

declare(strict_types=1);

namespace justinholtweb\digits\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\License;

/**
 * @method License[] all($db = null)
 * @method License|null one($db = null)
 * @method License|null nth(int $n, $db = null)
 */
class LicenseQuery extends ElementQuery
{
    public mixed $licenseKey = null;
    public mixed $email = null;
    public mixed $ownerId = null;
    public mixed $orderId = null;
    public mixed $downloadId = null;
    public mixed $source = null;
    public mixed $expiryDate = null;
    public ?int $expiringWithin = null;
    public ?bool $hasActivations = null;

    protected array $defaultOrderBy = ['digits_licenses.issuedDate' => SORT_DESC];

    public function licenseKey(mixed $value): self
    {
        $this->licenseKey = $value;

        return $this;
    }

    public function email(mixed $value): self
    {
        $this->email = $value;

        return $this;
    }

    public function ownerId(mixed $value): self
    {
        $this->ownerId = $value;

        return $this;
    }

    public function orderId(mixed $value): self
    {
        $this->orderId = $value;

        return $this;
    }

    public function downloadId(mixed $value): self
    {
        $this->downloadId = $value;

        return $this;
    }

    public function source(mixed $value): self
    {
        $this->source = $value;

        return $this;
    }

    public function expiryDate(mixed $value): self
    {
        $this->expiryDate = $value;

        return $this;
    }

    public function hasActivations(?bool $value = true): self
    {
        $this->hasActivations = $value;

        return $this;
    }

    /**
     * Licences that lapse inside the next `$days` days and have not lapsed already.
     *
     * A property as well as a method, because element index sources are `Craft::configure()`d on
     * to the query — and a criteria key with only a method behind it throws
     * `UnknownPropertyException` rather than calling it.
     */
    public function expiringWithin(?int $days): self
    {
        $this->expiringWithin = $days;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('digits_licenses');

        $this->query->select([
            'digits_licenses.licenseKey',
            'digits_licenses.ownerId',
            'digits_licenses.email',
            'digits_licenses.customerName',
            'digits_licenses.orderId',
            'digits_licenses.lineItemId',
            'digits_licenses.orderReference',
            'digits_licenses.status as licenseStatus',
            'digits_licenses.source',
            'digits_licenses.issuedDate',
            'digits_licenses.expiryDate',
            'digits_licenses.downloadLimit',
            'digits_licenses.downloadCount',
            'digits_licenses.activationLimit',
            'digits_licenses.activationCount',
            'digits_licenses.revokedDate',
            'digits_licenses.revokedReason',
            'digits_licenses.notes',
        ]);

        if ($this->licenseKey !== null) {
            $this->subQuery->andWhere(Db::parseParam('digits_licenses.licenseKey', $this->licenseKey));
        }

        if ($this->email !== null) {
            $this->subQuery->andWhere(Db::parseParam('digits_licenses.email', $this->email));
        }

        if ($this->ownerId !== null) {
            $this->subQuery->andWhere(Db::parseParam('digits_licenses.ownerId', $this->ownerId));
        }

        if ($this->orderId !== null) {
            $this->subQuery->andWhere(Db::parseParam('digits_licenses.orderId', $this->orderId));
        }

        if ($this->source !== null) {
            $this->subQuery->andWhere(Db::parseParam('digits_licenses.source', $this->source));
        }

        if ($this->expiryDate !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('digits_licenses.expiryDate', $this->expiryDate));
        }

        if ($this->expiringWithin !== null) {
            // `DATE_ATOM`, not `Db::prepareDateForDb()`: a bare `Y-m-d H:i:s` string is read by
            // `DateTimeHelper` as *system* time and converted to UTC a second time, so the window
            // silently matches nothing. The offset leaves nothing to guess.
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $then = (clone $now)->modify(sprintf('+%d days', $this->expiringWithin));

            $this->subQuery->andWhere(Db::parseDateParam('digits_licenses.expiryDate', [
                'and',
                '>= ' . $now->format(DATE_ATOM),
                '< ' . $then->format(DATE_ATOM),
            ]));
        }

        if ($this->downloadId !== null) {
            $this->subQuery->andWhere(['digits_licenses.id' => (new Query())
                ->select(['licenseId'])
                ->from([Table::LICENSE_DOWNLOADS])
                ->where(Db::parseParam('downloadId', $this->downloadId)),
            ]);
        }

        if ($this->hasActivations !== null) {
            $condition = ['digits_licenses.id' => (new Query())
                ->select(['licenseId'])
                ->from([Table::ACTIVATIONS])
                ->where(['status' => 'active']),
            ];

            $this->subQuery->andWhere($this->hasActivations ? $condition : ['not', $condition]);
        }

        return true;
    }

    /**
     * Digits' statuses are computed from a column and a date, not from `elements.enabled`.
     *
     * A licence that lapsed at midnight is expired without anything having run, so "expired" has
     * to be a comparison against `now()` rather than a flag somebody remembered to set. The
     * nightly sweep writes the column too, but only so that reports and exports agree with the
     * index — the query never depends on the sweep having run.
     */
    protected function statusCondition(string $status): mixed
    {
        $now = Db::prepareDateForDb(new \DateTime('now', new \DateTimeZone('UTC')));

        return match ($status) {
            License::STATUS_ACTIVE => [
                'and',
                ['digits_licenses.status' => License::STATUS_ACTIVE],
                ['or', ['digits_licenses.expiryDate' => null], ['>', 'digits_licenses.expiryDate', $now]],
            ],
            License::STATUS_EXPIRED => [
                'or',
                ['digits_licenses.status' => License::STATUS_EXPIRED],
                [
                    'and',
                    ['digits_licenses.status' => License::STATUS_ACTIVE],
                    ['not', ['digits_licenses.expiryDate' => null]],
                    ['<=', 'digits_licenses.expiryDate', $now],
                ],
            ],
            License::STATUS_REVOKED => ['digits_licenses.status' => License::STATUS_REVOKED],
            License::STATUS_DISABLED => ['digits_licenses.status' => License::STATUS_DISABLED],
            License::STATUS_PENDING => ['digits_licenses.status' => License::STATUS_PENDING],
            default => parent::statusCondition($status),
        };
    }
}
