<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\DownloadFile;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\FileRecord;
use justinholtweb\digits\records\VersionRecord;
use Throwable;

/**
 * Releases and the files inside them.
 *
 * The one invariant this service exists to hold: **a download has exactly one current version**.
 * Nothing else in the plugin is allowed to set `isCurrent`, because two current versions means the
 * delivery controller picks one arbitrarily and half the customers get the wrong build.
 *
 * Publishing is deliberately a *separate act* from saving. A draft version can be uploaded, its
 * notes written and its files checked days before anybody is meant to have it; `publish()` is the
 * moment it becomes real, and it is the moment that stamps `dateReleased` — which is the date
 * every "your licence covers updates until…" decision is then measured against.
 */
class Versions extends Component
{
    /** @var array<int, Version[]> */
    private array $_versionsByDownload = [];

    /** @var array<int, DownloadFile[]> */
    private array $_filesByVersion = [];

    /**
     * @return Version[] Newest release first, drafts at the top since they are what an editor is
     *                   coming back to.
     */
    public function getVersionsForDownload(int $downloadId, bool $includeDrafts = true): array
    {
        if (!isset($this->_versionsByDownload[$downloadId])) {
            $rows = $this->createQuery()
                ->where(['downloadId' => $downloadId])
                ->all();

            $versions = array_map(fn(array $row) => $this->rowToVersion($row), $rows);

            usort($versions, static function(Version $a, Version $b) {
                if ($a->getIsLive() !== $b->getIsLive()) {
                    return $a->getIsLive() ? 1 : -1;
                }

                $aTime = $a->dateReleased?->getTimestamp() ?? PHP_INT_MAX;
                $bTime = $b->dateReleased?->getTimestamp() ?? PHP_INT_MAX;

                return $bTime <=> $aTime ?: strcmp($b->getSortKey(), $a->getSortKey());
            });

            $this->_versionsByDownload[$downloadId] = $versions;
        }

        $versions = $this->_versionsByDownload[$downloadId];

        if ($includeDrafts) {
            return $versions;
        }

        return array_values(array_filter($versions, static fn(Version $v) => $v->getIsLive()));
    }

    public function getVersionById(int $id): ?Version
    {
        $row = $this->createQuery()->where(['id' => $id])->one();

        return $row !== null ? $this->rowToVersion($row) : null;
    }

    public function getVersionByNumber(int $downloadId, string $version): ?Version
    {
        $row = $this->createQuery()
            ->where(['downloadId' => $downloadId, 'version' => $version])
            ->one();

        return $row !== null ? $this->rowToVersion($row) : null;
    }

    public function getCurrentVersion(int $downloadId): ?Version
    {
        foreach ($this->getVersionsForDownload($downloadId, false) as $version) {
            if ($version->isCurrent) {
                return $version;
            }
        }

        return $this->getVersionsForDownload($downloadId, false)[0] ?? null;
    }

    /**
     * The versions a licence is entitled to, newest first.
     *
     * This is the "one year of updates" rule, and the reason a lapsed licence is not a dead one:
     * everything released while it was live stays available forever, and only the builds that came
     * out afterwards are behind the renewal. A download with update gating switched off hands over
     * everything regardless, which is the right default for an ebook and the wrong one for an app.
     *
     * @return Version[]
     */
    public function getVersionsAvailableTo(Download $download, ?License $license): array
    {
        $versions = $this->getVersionsForDownload((int)$download->id, false);

        if (!$download->gateUpdatesOnExpiry
            || $license === null
            || !Edition::allowsUpdateGating(Plugin::getInstance()->isPro())) {
            return $versions;
        }

        return array_values(array_filter(
            $versions,
            static fn(Version $version) => $license->coversVersionReleasedOn($version->dateReleased),
        ));
    }

    /** @return DownloadFile[] */
    public function getFilesForVersion(int $versionId): array
    {
        if (!isset($this->_filesByVersion[$versionId])) {
            $rows = (new Query())
                ->from([Table::FILES])
                ->where(['versionId' => $versionId])
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            $this->_filesByVersion[$versionId] = array_map(
                static fn(array $row) => new DownloadFile($row),
                $rows,
            );
        }

        return $this->_filesByVersion[$versionId];
    }

    public function getFileById(int $id): ?DownloadFile
    {
        $row = (new Query())->from([Table::FILES])->where(['id' => $id])->one();

        return $row !== null ? new DownloadFile($row) : null;
    }

    /**
     * Save a version and the files it holds.
     *
     * The files are written as a set: everything the caller handed over is inserted or updated,
     * and anything that used to be there and is not any more is deleted. In one transaction,
     * because a version that half-saved its files is a release nobody can trust.
     */
    public function saveVersion(Version $version, bool $runValidation = true): bool
    {
        $isNew = $version->id === null;

        if ($isNew && !$this->canAddVersion((int)$version->downloadId)) {
            $version->addError('version', Craft::t('digits', 'Digits Lite holds up to {max} versions per download. Upgrade to Pro for a full release history.', [
                'max' => Edition::LITE_MAX_VERSIONS,
            ]));

            return false;
        }

        if ($runValidation && !$version->validate()) {
            return false;
        }

        $record = $isNew ? new VersionRecord() : VersionRecord::findOne($version->id);

        if ($record === null) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record->downloadId = $version->downloadId;
            $record->version = $version->version;
            $record->releaseNotes = $version->releaseNotes;
            $record->status = $version->status;
            $record->isCurrent = $version->isCurrent;
            $record->dateReleased = Db::prepareDateForDb($version->dateReleased);
            $record->dateNotified = Db::prepareDateForDb($version->dateNotified);
            $record->sortOrder = $version->sortOrder;
            $record->save(false);

            $version->id = (int)$record->id;
            $version->uid = $record->uid;

            $this->saveFiles($version);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->clearCaches();

        return true;
    }

    /**
     * Make a draft real.
     *
     * Stamps the release date if it has none — the date is the version's whole relationship with
     * licence expiry, so a live version without one would be a release nobody could be told
     * whether they were entitled to.
     */
    public function publish(Version $version, bool $makeCurrent = true): bool
    {
        $version->status = Version::STATUS_LIVE;
        $version->dateReleased ??= new DateTime();

        if (!$this->saveVersion($version)) {
            return false;
        }

        if ($makeCurrent) {
            $this->makeCurrent($version);
        }

        return true;
    }

    /**
     * Point a download's "current" flag at this version.
     *
     * One statement clears the flag across the download and one sets it here, both inside a
     * transaction. Done the other way round — set then clear — a reader landing between the two
     * sees two current versions, which is precisely the state this method exists to prevent.
     */
    public function makeCurrent(Version $version): void
    {
        if ($version->id === null || $version->downloadId === null) {
            return;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()
                ->update(Table::VERSIONS, ['isCurrent' => false], ['downloadId' => $version->downloadId])
                ->execute();

            $db->createCommand()
                ->update(Table::VERSIONS, ['isCurrent' => true], ['id' => $version->id])
                ->execute();

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $version->isCurrent = true;
        $this->clearCaches();
    }

    /**
     * Delete a version, and hand "current" to whatever is left.
     *
     * A download left with no current version after a deletion is a download whose customers get
     * nothing, so the newest remaining live release takes over. There is no undo here on purpose:
     * the files themselves are assets and are untouched.
     */
    public function deleteVersionById(int $id): bool
    {
        $version = $this->getVersionById($id);

        if ($version === null) {
            return false;
        }

        $downloadId = (int)$version->downloadId;
        $wasCurrent = $version->isCurrent;

        VersionRecord::findOne($id)?->delete();

        $this->clearCaches();

        if ($wasCurrent) {
            $next = $this->getVersionsForDownload($downloadId, false)[0] ?? null;

            if ($next !== null) {
                $this->makeCurrent($next);
            }
        }

        return true;
    }

    /** Whether Lite's version cap leaves room for another one. */
    public function canAddVersion(int $downloadId): bool
    {
        $max = Edition::maxVersions(Plugin::getInstance()->isPro());

        if ($max === null) {
            return true;
        }

        return count($this->getVersionsForDownload($downloadId)) < $max;
    }

    /** Mark a release as having had its notices sent, so nothing sends them twice. */
    public function markNotified(Version $version, ?DateTime $date = null): void
    {
        $version->dateNotified = $date ?? new DateTime();

        Craft::$app->getDb()->createCommand()
            ->update(Table::VERSIONS, ['dateNotified' => Db::prepareDateForDb($version->dateNotified)], ['id' => $version->id])
            ->execute();

        $this->clearCaches();
    }

    public function clearCaches(): void
    {
        $this->_versionsByDownload = [];
        $this->_filesByVersion = [];
    }

    private function saveFiles(Version $version): void
    {
        $db = Craft::$app->getDb();
        $keptIds = [];
        $sortOrder = 0;

        foreach ($version->getFiles() as $file) {
            $record = $file->id !== null ? FileRecord::findOne($file->id) : null;
            $record ??= new FileRecord();

            $record->versionId = $version->id;
            $record->name = $file->name;
            $record->assetId = $file->assetId;
            $record->url = $file->getIsExternal() ? $file->url : null;
            $record->filename = $file->filename;
            $record->size = $file->size;
            $record->sortOrder = ++$sortOrder;
            $record->save(false);

            $file->id = (int)$record->id;

            // Stamped back on to the model, not just the row. `Access::checkFile()` compares the
            // file's `versionId` against the version it was asked about, so a freshly saved file
            // that never learned its own parent is refused as "unavailable" — and only on the
            // request that saved it, which is what makes it hard to find.
            $file->versionId = (int)$version->id;
            $keptIds[] = $file->id;
        }

        $condition = ['versionId' => $version->id];

        if ($keptIds !== []) {
            $condition = ['and', $condition, ['not', ['id' => $keptIds]]];
        }

        $db->createCommand()->delete(Table::FILES, $condition)->execute();
    }

    private function createQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'downloadId',
                'version',
                'releaseNotes',
                'isCurrent',
                'status',
                'dateReleased',
                'dateNotified',
                'sortOrder',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from([Table::VERSIONS]);
    }

    /**
     * A database row as a model.
     *
     * The dates need no conversion here: `craft\base\Model`'s constructor runs every attribute
     * named by `datetimeAttributes()` through `DateTimeHelper`, which reads a bare column value as
     * UTC — which is what it is.
     */
    private function rowToVersion(array $row): Version
    {
        $row['isCurrent'] = (bool)$row['isCurrent'];

        return new Version($row);
    }
}
