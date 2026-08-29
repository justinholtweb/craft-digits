<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\db\LicenseQuery;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\Plugin;
use Throwable;

/**
 * Issuing, withdrawing and counting against licences.
 *
 * Three things here are worth reading before changing anything:
 *
 * **`spend()` is a conditional update, not a read followed by a write.** Two tabs, two clicks, one
 * download left: a check-then-increment lets both through. The `UPDATE … WHERE downloadCount <
 * limit` lets exactly one through and tells the loser it lost, because the database is the only
 * thing in the request that can arbitrate between two PHP processes.
 *
 * **Revoking is a status, never a delete.** A refunded customer's licence is evidence — of what
 * they had, of when it stopped, of why — and the chargeback conversation six weeks later goes
 * badly without it. `revoke()` writes a reason and a date; nothing in Digits deletes a licence
 * except a person, deliberately, from the index.
 *
 * **Expiry is a comparison first and a column second.** A licence that runs out at midnight is
 * expired at midnight whether or not the sweep ran. {@see expireLapsed()} writes the column so
 * exports agree with the index, and nothing depends on it having done so.
 */
class Licenses extends Component
{
    public function getLicenseById(int $id): ?License
    {
        /** @var LicenseQuery $query */
        $query = License::find();

        return $query->id($id)->status(null)->one();
    }

    /**
     * A licence by the key somebody typed.
     *
     * Two passes: the exact key first, because that is what a machine sends, and then the
     * normalised comparison for a human who typed `l` for `1` or left the dashes out. The second
     * pass is a scan over the candidates rather than a `LIKE`, and it is bounded because the
     * normalised prefix still has to match.
     */
    public function getLicenseByKey(string $key): ?License
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        /** @var LicenseQuery $query */
        $query = License::find();
        $license = $query->licenseKey($key)->status(null)->one();

        if ($license !== null) {
            return $license;
        }

        $keys = Plugin::getInstance()->keys;
        $normalized = $keys->normalize($key);

        if ($normalized === '') {
            return null;
        }

        $candidates = (new Query())
            ->select(['id', 'licenseKey'])
            ->from([Table::LICENSES])
            ->all();

        foreach ($candidates as $candidate) {
            if ($keys->normalize((string)$candidate['licenseKey']) === $normalized) {
                return $this->getLicenseById((int)$candidate['id']);
            }
        }

        return null;
    }

    /** @return License[] */
    public function getLicensesForEmail(string $email, bool $usableOnly = false): array
    {
        /** @var LicenseQuery $query */
        $query = License::find();
        $query->email($email)->status($usableOnly ? License::STATUS_ACTIVE : null);

        return $query->all();
    }

    /** @return License[] */
    public function getLicensesForUser(User $user, bool $usableOnly = false): array
    {
        /** @var LicenseQuery $query */
        $query = License::find();

        // Both the ones claimed by the account and the ones still only bearing its email address:
        // a customer who bought as a guest and registered afterwards should not have to be told
        // that their purchases belong to somebody else.
        $query->andWhere([
            'or',
            ['digits_licenses.ownerId' => $user->id],
            ['digits_licenses.email' => $user->email],
        ]);

        $query->status($usableOnly ? License::STATUS_ACTIVE : null);

        return $query->all();
    }

    /** @return License[] */
    public function getLicensesForOrder(int $orderId): array
    {
        /** @var LicenseQuery $query */
        $query = License::find();

        return $query->orderId($orderId)->status(null)->all();
    }

    /**
     * Create a licence.
     *
     * `downloadIds` and `email` are the only things it cannot invent. The expiry, the limits and
     * the key all come from the downloads and the settings when they are not given, which is what
     * makes the Commerce bridge and the console command able to call this with three arguments.
     */
    public function issue(array $config): ?License
    {
        $downloadIds = array_values(array_unique(array_map('intval', $config['downloadIds'] ?? [])));

        if ($downloadIds === [] || empty($config['email'])) {
            return null;
        }

        $license = new License();
        $license->email = (string)$config['email'];
        $license->customerName = $config['customerName'] ?? null;
        $license->ownerId = $config['ownerId'] ?? null;
        $license->orderId = $config['orderId'] ?? null;
        $license->lineItemId = $config['lineItemId'] ?? null;
        $license->orderReference = $config['orderReference'] ?? null;
        $license->source = $config['source'] ?? License::SOURCE_MANUAL;
        $license->licenseStatus = $config['status'] ?? License::STATUS_ACTIVE;
        $license->notes = $config['notes'] ?? null;
        $license->downloadLimit = $config['downloadLimit'] ?? null;
        $license->activationLimit = $config['activationLimit'] ?? null;
        $license->issuedDate = $config['issuedDate'] ?? new DateTime();
        $license->setDownloadIds($downloadIds);

        if (array_key_exists('expiryDate', $config)) {
            $license->expiryDate = $config['expiryDate'];
        } else {
            $license->expiryDate = $this->defaultExpiry($downloadIds, $license->issuedDate);
        }

        if (!Craft::$app->getElements()->saveElement($license)) {
            Craft::warning('Could not issue a licence: ' . json_encode($license->getErrors()), Plugin::LOG_CATEGORY);

            return null;
        }

        Plugin::getInstance()->log->write(Log::EVENT_ISSUE, [
            'licenseId' => $license->id,
            'email' => $license->email,
            'detail' => $license->source,
        ]);

        return $license;
    }

    /**
     * When a licence issued now should lapse.
     *
     * The *longest* window of the downloads it covers, not the shortest — a bundle whose parts
     * disagree about how long support lasts should give the customer the better of the two. That
     * is the opposite of the rule for limits, and deliberately so: a limit is a cost the shop
     * carries and access is a promise it made.
     */
    public function defaultExpiry(array $downloadIds, ?DateTime $from = null): ?DateTime
    {
        $days = 0;

        foreach ($downloadIds as $id) {
            $download = Plugin::getInstance()->downloads->getDownloadById((int)$id);

            if ($download === null) {
                continue;
            }

            $downloadDays = $download->getEffectiveAccessDays();

            // One perpetual part makes the whole thing perpetual.
            if ($downloadDays === 0) {
                return null;
            }

            $days = max($days, $downloadDays);
        }

        return $days > 0 ? (clone($from ?? new DateTime()))->modify("+$days days") : null;
    }

    /** Replace what a licence unlocks. */
    public function setDownloadsForLicense(int $licenseId, array $downloadIds): void
    {
        $db = Craft::$app->getDb();
        $downloadIds = array_values(array_unique(array_map('intval', array_filter($downloadIds))));

        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()->delete(Table::LICENSE_DOWNLOADS, ['licenseId' => $licenseId])->execute();

            if ($downloadIds !== []) {
                $now = Db::prepareDateForDb(new DateTime());
                $rows = [];
                $sortOrder = 0;

                foreach ($downloadIds as $downloadId) {
                    $rows[] = [$licenseId, $downloadId, ++$sortOrder, $now, $now, \craft\helpers\StringHelper::UUID()];
                }

                $db->createCommand()->batchInsert(
                    Table::LICENSE_DOWNLOADS,
                    ['licenseId', 'downloadId', 'sortOrder', 'dateCreated', 'dateUpdated', 'uid'],
                    $rows,
                )->execute();
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Take one download off a licence's allowance.
     *
     * Returns false when there was nothing left to take, and that is the *only* thing the caller
     * may treat as "over the limit" — the check is the update. Everything about this method is
     * arranged so that the decision and the write are the same statement, because they are the two
     * halves that a second concurrent request gets between.
     */
    public function spend(License $license): bool
    {
        $limit = $license->getEffectiveDownloadLimit();

        $condition = ['id' => $license->id];

        if ($limit > 0) {
            $condition = ['and', $condition, ['<', 'downloadCount', $limit]];
        }

        $affected = (int)Craft::$app->getDb()->createCommand()
            ->update(
                Table::LICENSES,
                ['downloadCount' => new \yii\db\Expression('[[downloadCount]] + 1')],
                $condition,
            )
            ->execute();

        if ($affected === 0) {
            return false;
        }

        $license->downloadCount++;

        return true;
    }

    public function resetDownloadCount(License $license): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::LICENSES, ['downloadCount' => 0], ['id' => $license->id])
            ->execute();

        $license->downloadCount = 0;
    }

    /**
     * Withdraw access.
     *
     * Deliberately also kills every unspent link the customer already holds — the whole reason
     * tokens are rows rather than signatures. A refund that leaves a working URL in somebody's
     * inbox has not revoked anything.
     */
    public function revoke(License $license, ?string $reason = null): bool
    {
        $license->licenseStatus = License::STATUS_REVOKED;
        $license->revokedDate = new DateTime();
        $license->revokedReason = $reason;

        if (!Craft::$app->getElements()->saveElement($license, false)) {
            return false;
        }

        Plugin::getInstance()->links->revokeForLicense((int)$license->id);

        Plugin::getInstance()->log->write(Log::EVENT_REVOKE, [
            'licenseId' => $license->id,
            'email' => $license->email,
            'detail' => $reason,
        ]);

        return true;
    }

    /** Undo a revocation. The links stay dead — new ones are free. */
    public function restore(License $license): bool
    {
        $license->licenseStatus = License::STATUS_ACTIVE;
        $license->revokedDate = null;
        $license->revokedReason = null;

        if (!Craft::$app->getElements()->saveElement($license, false)) {
            return false;
        }

        Plugin::getInstance()->log->write(Log::EVENT_RESTORE, [
            'licenseId' => $license->id,
            'email' => $license->email,
        ]);

        return true;
    }

    /** Push a licence's expiry out, from whichever is later: today, or where it already stood. */
    public function extend(License $license, int $days): bool
    {
        $base = $license->expiryDate !== null && !$license->getIsExpired()
            ? clone $license->expiryDate
            : new DateTime();

        $license->expiryDate = $base->modify("+$days days");

        if ($license->licenseStatus === License::STATUS_EXPIRED) {
            $license->licenseStatus = License::STATUS_ACTIVE;
        }

        return Craft::$app->getElements()->saveElement($license, false);
    }

    /**
     * Write `expired` into the status column for everything that has lapsed.
     *
     * Cosmetic by design: every query already compares dates, so this changes no decision. What it
     * changes is that a CSV export, a report and the index all say the same word.
     */
    public function expireLapsed(): int
    {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        return (int)Craft::$app->getDb()->createCommand()
            ->update(
                Table::LICENSES,
                ['status' => License::STATUS_EXPIRED],
                [
                    'and',
                    ['status' => License::STATUS_ACTIVE],
                    ['not', ['expiryDate' => null]],
                    ['<=', 'expiryDate', $now],
                ],
            )
            ->execute();
    }

    /**
     * Attach a new account to the licences already bearing its email address.
     *
     * Run when somebody registers or activates. Without it, a guest checkout followed by "create
     * an account" produces a portal that is empty, which reads to the customer as having lost what
     * they paid for.
     */
    public function claimForUser(User $user): int
    {
        if ($user->email === null || $user->email === '') {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->update(
                Table::LICENSES,
                ['ownerId' => $user->id],
                ['and', ['email' => $user->email], ['ownerId' => null]],
            )
            ->execute();
    }

    /** Put `activationCount` back in step with the activation rows. */
    public function recountActivations(?int $licenseId = null): int
    {
        $counts = (new Query())
            ->select(['licenseId', 'total' => 'COUNT(*)'])
            ->from([Table::ACTIVATIONS])
            ->where(['status' => 'active'])
            ->groupBy(['licenseId'])
            ->pairs();

        $ids = $licenseId !== null
            ? [$licenseId]
            : (new Query())->select(['id'])->from([Table::LICENSES])->column();

        $db = Craft::$app->getDb();
        $changed = 0;

        foreach ($ids as $id) {
            $changed += (int)$db->createCommand()
                ->update(Table::LICENSES, ['activationCount' => (int)($counts[$id] ?? 0)], ['id' => $id])
                ->execute();
        }

        return $changed;
    }

    /** @return Download[] The downloads a licence unlocks, without hydrating the licence itself. */
    public function getDownloadsForLicense(int $licenseId): array
    {
        $ids = (new Query())
            ->select(['downloadId'])
            ->from([Table::LICENSE_DOWNLOADS])
            ->where(['licenseId' => $licenseId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->column();

        if ($ids === []) {
            return [];
        }

        /** @var LicenseQuery|\justinholtweb\digits\elements\db\DownloadQuery $query */
        $query = Download::find();

        return $query->id($ids)->status(null)->all();
    }
}
