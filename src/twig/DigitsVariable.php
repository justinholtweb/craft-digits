<?php

declare(strict_types=1);

namespace justinholtweb\digits\twig;

use Craft;
use craft\elements\User;
use justinholtweb\digits\elements\db\DownloadQuery;
use justinholtweb\digits\elements\db\LicenseQuery;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\AccessVerdict;
use justinholtweb\digits\models\DownloadFile;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;
use yii\base\Behavior;

/**
 * `craft.digits.*`.
 *
 * Deliberately thin. Everything that can be an element query is one, so a template is never limited
 * to what this class thought of — `craft.digits.downloads` is an ordinary `DownloadQuery` and every
 * method a Craft developer already knows works on it.
 *
 * The two that are not queries are the important ones. {@see can()} hands back the same
 * {@see AccessVerdict} the delivery controller will produce, and {@see linksFor()} mints URLs only
 * when that verdict allows it — so a template physically cannot render a button the download
 * endpoint would refuse.
 */
class DigitsVariable extends Behavior
{
    /**
     * An unfiltered download query.
     *
     * ```twig
     * {% set manuals = craft.digits.downloads.accessType('public').all() %}
     * ```
     */
    public function getDownloads(array $criteria = []): DownloadQuery
    {
        /** @var DownloadQuery $query */
        $query = Download::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    /** One download, by ID or SKU. */
    public function download(mixed $identifier): ?Download
    {
        if (is_numeric($identifier)) {
            return Plugin::getInstance()->downloads->getDownloadById((int)$identifier);
        }

        return Plugin::getInstance()->downloads->getDownloadBySku((string)$identifier);
    }

    public function getLicenses(array $criteria = []): LicenseQuery
    {
        /** @var LicenseQuery $query */
        $query = License::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    /**
     * The signed-in customer's licences.
     *
     * Both the ones their account owns and the ones still bearing only their email address, so a
     * guest purchase followed by registering does not look to the customer like a lost order.
     *
     * @return License[]
     */
    public function myLicenses(bool $usableOnly = false): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null
            ? Plugin::getInstance()->licenses->getLicensesForUser($user, $usableOnly)
            : [];
    }

    /** @return Download[] Everything the signed-in customer may download. */
    public function myDownloads(): array
    {
        $downloads = [];

        foreach ($this->myLicenses() as $license) {
            foreach ($license->getDownloads() as $download) {
                $downloads[(int)$download->id] = $download;
            }
        }

        return array_values($downloads);
    }

    /**
     * May the visitor have this?
     *
     * Returns the verdict, not a boolean, because a template usually wants to say something
     * different for "sign in" than for "you have not bought this" — and the verdict already knows
     * the sentence.
     *
     * ```twig
     * {% set verdict = craft.digits.can(download) %}
     * {% if verdict.allowed %}…{% else %}<p>{{ verdict.message }}</p>{% endif %}
     * ```
     */
    public function can(?Download $download, ?License $license = null): AccessVerdict
    {
        return Plugin::getInstance()->access->check($download, $license, $this->currentUser());
    }

    public function canHaveVersion(?Download $download, ?Version $version, ?License $license = null): AccessVerdict
    {
        return Plugin::getInstance()->access->checkVersion($download, $version, $license, $this->currentUser());
    }

    /**
     * A download URL for one file, or null when the visitor may not have it.
     *
     * Minting a link is a write, so this is not something to call speculatively in a loop over a
     * catalogue — call it where a button is actually being drawn.
     */
    public function url(?Download $download, ?DownloadFile $file = null, ?Version $version = null, ?License $license = null): ?string
    {
        if ($download === null) {
            return null;
        }

        $plugin = Plugin::getInstance();
        $version ??= $download->getCurrentVersion();
        $file ??= $version?->getFiles()[0] ?? null;

        if ($version === null || $file === null) {
            return null;
        }

        $verdict = $plugin->access->checkFile($download, $version, $file, $license, $this->currentUser());

        if (!$verdict->allowed) {
            return null;
        }

        return $plugin->links->urlForFile($file, $version, $download, $verdict->license);
    }

    /**
     * Every file of a version, with a link each.
     *
     * @return array[] `{file, url, verdict}` per file, so a template can render the refused ones as
     *                 something other than nothing at all.
     */
    public function linksFor(?Download $download, ?Version $version = null, ?License $license = null): array
    {
        if ($download === null) {
            return [];
        }

        $plugin = Plugin::getInstance();
        $version ??= $download->getCurrentVersion();

        if ($version === null) {
            return [];
        }

        $links = [];

        foreach ($version->getFiles() as $file) {
            $verdict = $plugin->access->checkFile($download, $version, $file, $license, $this->currentUser());

            $links[] = [
                'file' => $file,
                'verdict' => $verdict,
                'url' => $verdict->allowed
                    ? $plugin->links->urlForFile($file, $version, $download, $verdict->license)
                    : null,
            ];
        }

        return $links;
    }

    /**
     * The versions a visitor is entitled to, newest first.
     *
     * @return Version[]
     */
    public function versionsFor(?Download $download, ?License $license = null): array
    {
        if ($download === null) {
            return [];
        }

        $plugin = Plugin::getInstance();
        $license ??= $plugin->access->findLicense($download, $this->currentUser());

        return $plugin->versions->getVersionsAvailableTo($download, $license);
    }

    public function portalUrl(array $params = []): string
    {
        return Plugin::getInstance()->getSettings()->getPortalUrl($params);
    }

    /** How many downloads a licence has left, or null when there is no limit. */
    public function remaining(?License $license): ?int
    {
        return $license?->getDownloadsRemaining();
    }

    private function currentUser(): ?User
    {
        return Craft::$app->getUser()->getIdentity();
    }
}
