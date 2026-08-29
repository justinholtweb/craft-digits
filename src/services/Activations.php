<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\Activation;
use justinholtweb\digits\models\ActivationResult;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\ActivationRecord;

/**
 * Seats.
 *
 * The rule that shapes everything here: **the same installation activating twice is one seat.** A
 * customer who reinstalls, restores a backup, or clones staging from production should not silently
 * burn through their allowance, and a vendor whose licence limit can be exhausted by a deploy
 * script will hear about it. So an activation is keyed on the normalised instance and *reactivated
 * in place* rather than inserted again.
 *
 * The seat limit is checked with a conditional update for the same reason licence downloads are:
 * the check and the write have to be one statement, or two machines starting at once both get in.
 */
class Activations extends Component
{
    /** @return Activation[] */
    public function getActivationsForLicense(int $licenseId): array
    {
        $rows = (new Query())
            ->from([Table::ACTIVATIONS])
            ->where(['licenseId' => $licenseId])
            ->orderBy(['dateActivated' => SORT_DESC])
            ->all();

        return array_map(static fn(array $row) => new Activation($row), $rows);
    }

    public function getActivation(int $licenseId, string $instance): ?Activation
    {
        $row = (new Query())
            ->from([Table::ACTIVATIONS])
            ->where([
                'licenseId' => $licenseId,
                'instance' => Activation::normalizeInstance($instance),
            ])
            ->one();

        return $row !== null ? new Activation($row) : null;
    }

    /**
     * Turn a key and an instance into a seat.
     *
     * The order of the checks is the order a vendor would want them reported: is the key real, is
     * the licence live, and only then is there room. A customer whose licence lapsed should be told
     * that, not told they are out of seats.
     */
    public function activate(string $key, string $instance, ?string $label = null): ActivationResult
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsActivations($plugin->isPro()) || !$plugin->getSettings()->enableActivationApi) {
            return ActivationResult::fail(ActivationResult::DISABLED);
        }

        $instance = Activation::normalizeInstance($instance);

        if ($instance === '') {
            return ActivationResult::fail(ActivationResult::NO_INSTANCE);
        }

        $license = $plugin->licenses->getLicenseByKey($key);

        if ($license === null) {
            return ActivationResult::fail(ActivationResult::UNKNOWN_KEY);
        }

        $failure = match ($license->getStatus()) {
            License::STATUS_REVOKED => ActivationResult::REVOKED,
            License::STATUS_EXPIRED => ActivationResult::EXPIRED,
            License::STATUS_ACTIVE => null,
            default => ActivationResult::NOT_ACTIVE,
        };

        if ($failure !== null) {
            return ActivationResult::fail($failure, $license);
        }

        $existing = $this->getActivation((int)$license->id, $instance);

        // Already here. Reactivating in place rather than inserting is what keeps a reinstall from
        // costing a seat, and touching `dateLastSeen` is what lets a vendor see which seats are
        // real.
        if ($existing !== null) {
            $wasActive = $existing->getIsActive();

            if (!$wasActive && !$this->claimSeat($license)) {
                return ActivationResult::fail(ActivationResult::SEAT_LIMIT, $license);
            }

            $record = ActivationRecord::findOne($existing->id);
            $record->status = Activation::STATUS_ACTIVE;
            $record->label = $label ?? $record->label;
            $record->dateDeactivated = null;
            $record->dateLastSeen = Db::prepareDateForDb(new DateTime());
            $record->ip = Craft::$app->getRequest()->getIsConsoleRequest() ? $record->ip : Craft::$app->getRequest()->getUserIP();
            $record->save(false);

            $plugin->log->write(Log::EVENT_ACTIVATE, [
                'licenseId' => $license->id,
                'email' => $license->email,
                'detail' => $instance,
            ]);

            return ActivationResult::ok($license, new Activation($record->getAttributes()));
        }

        if (!$this->claimSeat($license)) {
            return ActivationResult::fail(ActivationResult::SEAT_LIMIT, $license);
        }

        $request = Craft::$app->getRequest();

        $record = new ActivationRecord();
        $record->licenseId = $license->id;
        $record->instance = $instance;
        $record->label = $label;
        $record->status = Activation::STATUS_ACTIVE;
        $record->dateActivated = Db::prepareDateForDb(new DateTime());
        $record->dateLastSeen = $record->dateActivated;

        if (!$request->getIsConsoleRequest()) {
            $record->ip = $request->getUserIP();
            $record->userAgent = mb_substr((string)$request->getUserAgent(), 0, 255) ?: null;
        }

        $record->save(false);

        $plugin->log->write(Log::EVENT_ACTIVATE, [
            'licenseId' => $license->id,
            'email' => $license->email,
            'detail' => $instance,
        ]);

        return ActivationResult::ok($license, new Activation($record->getAttributes()));
    }

    public function deactivate(string $key, string $instance): ActivationResult
    {
        $plugin = Plugin::getInstance();
        $license = $plugin->licenses->getLicenseByKey($key);

        if ($license === null) {
            return ActivationResult::fail(ActivationResult::UNKNOWN_KEY);
        }

        $activation = $this->getActivation((int)$license->id, $instance);

        if ($activation === null || !$activation->getIsActive()) {
            return ActivationResult::fail(ActivationResult::NOT_ACTIVATED, $license);
        }

        return $this->deactivateById((int)$activation->id)
            ? ActivationResult::ok($license)
            : ActivationResult::fail(ActivationResult::NOT_ACTIVATED, $license);
    }

    /**
     * Free a seat.
     *
     * The row stays, deactivated. A vendor asking "has this customer ever run it on that server"
     * has a right to an answer, and deleting the row throws it away for the sake of one integer
     * that is already stored separately.
     */
    public function deactivateById(int $activationId): bool
    {
        $record = ActivationRecord::findOne($activationId);

        if ($record === null || $record->status !== Activation::STATUS_ACTIVE) {
            return false;
        }

        $record->status = Activation::STATUS_DEACTIVATED;
        $record->dateDeactivated = Db::prepareDateForDb(new DateTime());
        $record->save(false);

        // `GREATEST(activationCount - 1, 0)` looks like the obvious guard and is a trap: the column
        // is unsigned, so MySQL evaluates `0 - 1` *before* `GREATEST` ever sees it and either errors
        // or wraps to 18446744073709551615. The cast is what makes the subtraction signed.
        Craft::$app->getDb()->createCommand()
            ->update(
                Table::LICENSES,
                ['activationCount' => new \yii\db\Expression('GREATEST(CAST([[activationCount]] AS SIGNED) - 1, 0)')],
                ['id' => $record->licenseId],
            )
            ->execute();

        $license = Plugin::getInstance()->licenses->getLicenseById((int)$record->licenseId);

        Plugin::getInstance()->log->write(Log::EVENT_DEACTIVATE, [
            'licenseId' => $record->licenseId,
            'email' => $license?->email,
            'detail' => $record->instance,
        ]);

        return true;
    }

    /** A licence check that does not change anything except when the vendor's software last called. */
    public function check(string $key, ?string $instance = null): ActivationResult
    {
        $plugin = Plugin::getInstance();
        $license = $plugin->licenses->getLicenseByKey($key);

        if ($license === null) {
            return ActivationResult::fail(ActivationResult::UNKNOWN_KEY);
        }

        $activation = $instance !== null ? $this->getActivation((int)$license->id, $instance) : null;

        if ($activation !== null && $activation->getIsActive()) {
            Craft::$app->getDb()->createCommand()
                ->update(Table::ACTIVATIONS, ['dateLastSeen' => Db::prepareDateForDb(new DateTime())], ['id' => $activation->id])
                ->execute();
        }

        $failure = match ($license->getStatus()) {
            License::STATUS_REVOKED => ActivationResult::REVOKED,
            License::STATUS_EXPIRED => ActivationResult::EXPIRED,
            License::STATUS_ACTIVE => null,
            default => ActivationResult::NOT_ACTIVE,
        };

        if ($failure !== null) {
            return ActivationResult::fail($failure, $license);
        }

        if ($instance !== null && ($activation === null || !$activation->getIsActive())) {
            return ActivationResult::fail(ActivationResult::NOT_ACTIVATED, $license);
        }

        return ActivationResult::ok($license, $activation);
    }

    /**
     * Take a seat, if there is one.
     *
     * `UPDATE … WHERE activationCount < limit` — the same conditional-update trick the download
     * counter uses, and for the same reason: a read followed by a write lets two machines starting
     * simultaneously both take the last seat.
     */
    private function claimSeat(License $license): bool
    {
        $limit = $license->getEffectiveActivationLimit();
        $condition = ['id' => $license->id];

        if ($limit > 0) {
            $condition = ['and', $condition, ['<', 'activationCount', $limit]];
        }

        $affected = (int)Craft::$app->getDb()->createCommand()
            ->update(
                Table::LICENSES,
                ['activationCount' => new \yii\db\Expression('[[activationCount]] + 1')],
                $condition,
            )
            ->execute();

        if ($affected === 0) {
            return false;
        }

        $license->activationCount++;

        return true;
    }
}
