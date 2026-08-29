<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\User;
use justinholtweb\digits\elements\db\LicenseQuery;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\AccessVerdict;
use justinholtweb\digits\models\DownloadFile;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;

/**
 * The one place that decides whether somebody may have a file.
 *
 * Every caller in the plugin asks this and reads the same {@see AccessVerdict} back: the Twig
 * helper deciding whether to draw a button, the controller minting a link, the portal listing what
 * a customer owns, and the controller handing over the bytes. That is not tidiness — it is the
 * only arrangement in which a template cannot offer a download that the delivery endpoint would
 * refuse, which is otherwise the most common bug in software of this shape and the one the
 * customer reports rather than the developer.
 *
 * Note what is *not* here: token checking. A token is a claim about identity, not an entitlement,
 * so {@see Links::redeem()} turns a token into a licence and this class then asks the same
 * questions it would have asked a signed-in customer. A revoked licence is refused whether the
 * request arrived with a session or with a link from an email.
 */
class Access extends Component
{
    /**
     * May this identity have this download at all?
     *
     * `$license` is optional and is the answer when a caller already knows which one it is asking
     * about — the portal listing a specific licence's files, or a link redemption. Left null, the
     * best licence the identity holds is found.
     */
    public function check(
        ?Download $download,
        ?License $license = null,
        ?User $user = null,
        ?string $email = null,
    ): AccessVerdict {
        if ($download === null) {
            return AccessVerdict::deny(AccessVerdict::REASON_NOT_FOUND);
        }

        // A disabled download is off for everybody, including the customer holding a live licence
        // for it. That is what disabling is for — a file withdrawn because it was wrong.
        if ($download->getStatus() !== Element::STATUS_ENABLED) {
            return AccessVerdict::deny(AccessVerdict::REASON_DOWNLOAD_DISABLED, $download);
        }

        $user ??= Craft::$app->getUser()->getIdentity();

        return match ($download->accessType) {
            Download::ACCESS_PUBLIC => AccessVerdict::allow($download),
            Download::ACCESS_LOGIN => $user !== null
                ? AccessVerdict::allow($download)
                : AccessVerdict::deny(AccessVerdict::REASON_LOGIN_REQUIRED, $download),
            Download::ACCESS_GROUPS => $this->checkGroups($download, $user),
            default => $this->checkLicense($download, $license, $user, $email),
        };
    }

    /**
     * May they have *this version* of it?
     *
     * Layered on top of {@see check()} rather than folded into it, because the two refusals mean
     * different things to a customer: "you do not have this" is a purchase, and "your access ended
     * before this build came out" is a renewal, and the second one should still leave the earlier
     * builds downloadable.
     */
    public function checkVersion(
        ?Download $download,
        ?Version $version,
        ?License $license = null,
        ?User $user = null,
        ?string $email = null,
    ): AccessVerdict {
        $verdict = $this->check($download, $license, $user, $email);

        if (!$verdict->allowed) {
            return $verdict->withTarget($version, null);
        }

        if ($version === null || !$version->getIsLive()) {
            return AccessVerdict::deny(AccessVerdict::REASON_UNAVAILABLE, $download, $verdict->license, $version);
        }

        $license = $verdict->license;

        if ($license !== null
            && $download->gateUpdatesOnExpiry
            && \justinholtweb\digits\models\Edition::allowsUpdateGating(Plugin::getInstance()->isPro())
            && !$license->coversVersionReleasedOn($version->dateReleased)) {
            return AccessVerdict::deny(AccessVerdict::REASON_UPDATE_GATED, $download, $license, $version);
        }

        return AccessVerdict::allow($download, $license, $version);
    }

    /** And this particular file inside it? */
    public function checkFile(
        ?Download $download,
        ?Version $version,
        ?DownloadFile $file,
        ?License $license = null,
        ?User $user = null,
        ?string $email = null,
    ): AccessVerdict {
        $verdict = $this->checkVersion($download, $version, $license, $user, $email);

        if (!$verdict->allowed) {
            return $verdict->withTarget($version, $file);
        }

        if ($file === null || (int)$file->versionId !== (int)$version->id || !$file->getIsAvailable()) {
            return AccessVerdict::deny(AccessVerdict::REASON_UNAVAILABLE, $download, $verdict->license, $version, $file);
        }

        return AccessVerdict::allow($download, $verdict->license, $version, $file);
    }

    /**
     * The licence an identity should be judged on for a given download.
     *
     * Where somebody holds several — a renewal, a bundle and a single purchase all covering the
     * same file — the most generous *usable* one wins: perpetual before dated, later expiry before
     * earlier, and a licence with downloads left before one that has run out. Anything else means
     * a customer who bought twice is refused because Digits picked the wrong row.
     */
    public function findLicense(Download $download, ?User $user = null, ?string $email = null): ?License
    {
        $emails = array_values(array_filter([
            $email,
            $user?->email,
        ]));

        if ($user === null && $emails === []) {
            return null;
        }

        /** @var LicenseQuery $query */
        $query = License::find();
        $query->downloadId($download->getCanonicalId())->status(null);

        $condition = ['or'];

        if ($user !== null) {
            $condition[] = ['digits_licenses.ownerId' => $user->id];
        }

        if ($emails !== []) {
            $condition[] = ['digits_licenses.email' => $emails];
        }

        $query->andWhere($condition);

        /** @var License[] $licenses */
        $licenses = $query->all();

        if ($licenses === []) {
            return null;
        }

        usort($licenses, static function(License $a, License $b) {
            // Usable first, then perpetual, then the one that lasts longest.
            if ($a->getIsUsable() !== $b->getIsUsable()) {
                return $a->getIsUsable() ? -1 : 1;
            }

            $aExpiry = $a->expiryDate?->getTimestamp() ?? PHP_INT_MAX;
            $bExpiry = $b->expiryDate?->getTimestamp() ?? PHP_INT_MAX;

            return $bExpiry <=> $aExpiry;
        });

        return $licenses[0];
    }

    private function checkGroups(Download $download, ?User $user): AccessVerdict
    {
        if ($user === null) {
            return AccessVerdict::deny(AccessVerdict::REASON_LOGIN_REQUIRED, $download);
        }

        $allowed = $download->getGroupIds();

        if ($allowed === []) {
            return AccessVerdict::deny(AccessVerdict::REASON_GROUP_REQUIRED, $download);
        }

        // Admins are not automatically in every group, and Digits does not pretend they are: a
        // gated file is gated, and an administrator who needs it can grant themselves the group.
        foreach ($user->getGroups() as $group) {
            if (in_array((int)$group->id, $allowed, true)) {
                return AccessVerdict::allow($download);
            }
        }

        return AccessVerdict::deny(AccessVerdict::REASON_GROUP_REQUIRED, $download);
    }

    private function checkLicense(Download $download, ?License $license, ?User $user, ?string $email): AccessVerdict
    {
        $license ??= $this->findLicense($download, $user, $email);

        if ($license === null) {
            // A guest with no email to go on is told to sign in rather than told they have nothing
            // — because they may well have something, under an address this request never saw.
            return $user === null && ($email === null || $email === '')
                ? AccessVerdict::deny(AccessVerdict::REASON_LOGIN_REQUIRED, $download)
                : AccessVerdict::deny(AccessVerdict::REASON_NO_LICENSE, $download);
        }

        if (!$license->coversDownload($download->getCanonicalId())) {
            return AccessVerdict::deny(AccessVerdict::REASON_NO_LICENSE, $download, $license);
        }

        $reason = match ($license->getStatus()) {
            License::STATUS_REVOKED => AccessVerdict::REASON_REVOKED,
            License::STATUS_DISABLED => AccessVerdict::REASON_DISABLED,
            License::STATUS_PENDING => AccessVerdict::REASON_PENDING,
            License::STATUS_EXPIRED => AccessVerdict::REASON_EXPIRED,
            default => null,
        };

        // A lapsed licence on a download that gates *updates* is not a refusal — it is the whole
        // point of the model. The customer keeps everything released while they were paying, and
        // {@see checkVersion()} is where the builds that came out afterwards are turned away. With
        // update gating off, expiry means what it says and nothing is downloadable.
        if ($reason === AccessVerdict::REASON_EXPIRED
            && $download->gateUpdatesOnExpiry
            && \justinholtweb\digits\models\Edition::allowsUpdateGating(Plugin::getInstance()->isPro())) {
            $reason = null;
        }

        if ($reason !== null) {
            return AccessVerdict::deny($reason, $download, $license);
        }

        if ($license->getIsOverLimit()) {
            return AccessVerdict::deny(AccessVerdict::REASON_LIMIT_REACHED, $download, $license);
        }

        return AccessVerdict::allow($download, $license);
    }
}
