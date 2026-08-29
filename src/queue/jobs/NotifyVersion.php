<?php

declare(strict_types=1);

namespace justinholtweb\digits\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\digits\elements\db\LicenseQuery;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\Plugin;

/**
 * Tell everybody holding a licence that a new version is out.
 *
 * Queued, always. A download with four thousand licensees is four thousand emails, and nobody's
 * "Publish" click should hold a browser open while they go out — nor should a mail server hiccup
 * two thirds of the way through cost the release.
 *
 * Restarting it is safe and is the intended repair: the notice rows carry a unique index on
 * (version, licence), so a second run mails only the people the first one never reached.
 */
class NotifyVersion extends BaseJob
{
    public int $versionId;

    /** Set when the job is queued, so a licence issued *after* the release is not told about it. */
    public ?string $issuedBefore = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $version = $plugin->versions->getVersionById($this->versionId);

        if ($version === null || !$version->getIsLive()) {
            return;
        }

        $download = $version->getDownload();

        if ($download === null || !$download->notifyOnRelease) {
            return;
        }

        /** @var LicenseQuery $query */
        $query = License::find();
        $query->downloadId((int)$download->id)->status(License::STATUS_ACTIVE);

        if ($this->issuedBefore !== null) {
            $query->andWhere(['<=', 'digits_licenses.issuedDate', $this->issuedBefore]);
        }

        $total = (int)$query->count();
        $done = 0;

        foreach ($query->all() as $license) {
            /** @var License $license */
            $this->setProgress($queue, $total > 0 ? $done / $total : 1, Craft::t('digits', 'Telling {email}', [
                'email' => $license->email,
            ]));

            $plugin->notifications->sendVersionReleased($version, $download, $license);
            $done++;
        }

        $plugin->versions->markNotified($version);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('digits', 'Sending release notices');
    }
}
