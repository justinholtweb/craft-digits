<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\models\AccessVerdict;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\LogRecord;
use Throwable;

/**
 * The append-only record of what happened.
 *
 * Separate from the counters on purpose. `downloadCount` answers "has this key run out"; this
 * answers "who took what, from where, and when" — which is the question asked months later, in an
 * argument about a chargeback, and which cannot be reconstructed from a counter afterwards.
 *
 * Refusals are written as well as successes, and that is the half people leave out. "Why can't my
 * customer download the file" is a ticket every shop gets, and without the denied rows the only
 * answer available is a guess.
 */
class Log extends Component
{
    public const EVENT_DOWNLOAD = 'download';
    public const EVENT_DENIED = 'denied';
    public const EVENT_ISSUE = 'issue';
    public const EVENT_REVOKE = 'revoke';
    public const EVENT_RESTORE = 'restore';
    public const EVENT_ACTIVATE = 'activate';
    public const EVENT_DEACTIVATE = 'deactivate';
    public const EVENT_LINK = 'link';
    public const EVENT_NOTICE = 'notice';

    /**
     * Write one line.
     *
     * Never throws. A logging failure must not be able to stop a download that has already been
     * authorised — the customer paid for the file, not for the audit trail — so anything that goes
     * wrong here goes to Craft's own log and the request carries on.
     */
    public function write(string $event, array $attributes = []): void
    {
        try {
            $request = Craft::$app->getRequest();

            $record = new LogRecord();
            $record->event = $event;
            $record->licenseId = $attributes['licenseId'] ?? null;
            $record->downloadId = $attributes['downloadId'] ?? null;
            $record->versionId = $attributes['versionId'] ?? null;
            $record->fileId = $attributes['fileId'] ?? null;
            $record->tokenId = $attributes['tokenId'] ?? null;
            $record->userId = $attributes['userId'] ?? (Craft::$app->getUser()?->getId());
            $record->email = $attributes['email'] ?? null;
            $record->reason = $attributes['reason'] ?? null;
            $record->detail = isset($attributes['detail']) ? mb_substr((string)$attributes['detail'], 0, 500) : null;
            $record->bytes = $attributes['bytes'] ?? null;

            if (!$request->getIsConsoleRequest()) {
                $record->ip = $attributes['ip'] ?? $request->getUserIP();
                $record->userAgent = mb_substr((string)$request->getUserAgent(), 0, 500) ?: null;
            }

            $record->save(false);
        } catch (Throwable $e) {
            Craft::warning('Could not write a Digits log row: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /** A refusal, taken straight from the verdict that produced it. */
    public function denied(AccessVerdict $verdict, array $attributes = []): void
    {
        $this->write(self::EVENT_DENIED, array_merge([
            'reason' => $verdict->reason,
            'licenseId' => $verdict->license?->id,
            'downloadId' => $verdict->download?->id,
            'versionId' => $verdict->version?->id,
            'fileId' => $verdict->file?->id,
        ], $attributes));
    }

    /**
     * @return array[] Raw rows, newest first. The log is read as a table and never hydrated into
     *                 models, because nothing ever mutates it.
     */
    public function find(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        return $this->baseQuery($criteria)
            ->orderBy(['digits_log.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();
    }

    public function count(array $criteria = []): int
    {
        return (int)$this->baseQuery($criteria)->count();
    }

    /** Downloads per day over a window, for the chart on a download's edit screen. */
    public function dailyCounts(int $downloadId, int $days = 30): array
    {
        $since = (new DateTime('now', new DateTimeZone('UTC')))->modify("-$days days");

        $rows = (new Query())
            ->select([
                'day' => 'DATE([[dateCreated]])',
                'total' => 'COUNT(*)',
            ])
            ->from([Table::LOG])
            ->where(['event' => self::EVENT_DOWNLOAD, 'downloadId' => $downloadId])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)])
            ->groupBy(['day'])
            ->orderBy(['day' => SORT_ASC])
            ->all();

        return array_column($rows, 'total', 'day');
    }

    /**
     * Delete rows older than the retention setting.
     *
     * In batches rather than one statement: a shop that has never pruned can have millions of
     * rows, and a single `DELETE` across all of them holds a lock long enough to time out the
     * request that triggered garbage collection.
     */
    public function prune(?int $days = null): int
    {
        $days = $days ?? Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify("-$days days"));
        $deleted = 0;

        do {
            $ids = (new Query())
                ->select(['id'])
                ->from([Table::LOG])
                ->where(['<', 'dateCreated', $cutoff])
                ->limit(1000)
                ->column();

            if ($ids !== []) {
                $deleted += (int)Craft::$app->getDb()->createCommand()
                    ->delete(Table::LOG, ['id' => $ids])
                    ->execute();
            }
        } while (count($ids) === 1000);

        return $deleted;
    }

    /**
     * The base query, with the names a log screen needs joined on.
     *
     * A download's name is on `elements_sites`, not on Digits' own table, and the join is a left
     * one on purpose: a row about a download that has since been deleted is exactly the row
     * somebody is looking for, and an inner join would hide it.
     */
    private function baseQuery(array $criteria): Query
    {
        $query = (new Query())
            ->select([
                'digits_log.*',
                'downloadTitle' => 'downloads.title',
                'licenseKey' => 'licenses.licenseKey',
                'versionNumber' => 'versions.version',
            ])
            ->from(['digits_log' => Table::LOG])
            ->leftJoin(['licenses' => Table::LICENSES], '[[licenses.id]] = [[digits_log.licenseId]]')
            ->leftJoin(['versions' => Table::VERSIONS], '[[versions.id]] = [[digits_log.versionId]]')
            ->leftJoin(
                ['downloads' => CraftTable::ELEMENTS_SITES],
                '[[downloads.elementId]] = [[digits_log.downloadId]]',
            );

        foreach (['event', 'licenseId', 'downloadId', 'versionId', 'userId', 'reason', 'email'] as $key) {
            if (isset($criteria[$key])) {
                $query->andWhere(['digits_log.' . $key => $criteria[$key]]);
            }
        }

        if (isset($criteria['since'])) {
            $query->andWhere(['>=', 'digits_log.dateCreated', Db::prepareDateForDb($criteria['since'])]);
        }

        return $query;
    }
}
