<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\AccessVerdict;
use justinholtweb\digits\models\DownloadFile;
use justinholtweb\digits\models\LinkToken;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\TokenRecord;

/**
 * Minting download links, and taking them back.
 *
 * ## Why these are rows and not signatures
 *
 * A signed URL is smaller, needs no table and cannot be revoked. Digits has to revoke — a refund
 * that leaves a working link in somebody's inbox has revoked nothing — and it has to count, and it
 * has to be able to answer "was this link used, and from where" three weeks later. All three of
 * those are a row.
 *
 * ## What is stored
 *
 * The **hash** of the token, never the token. Digits hands the token to the customer once, in the
 * URL, and keeps `hash('sha256', $token)`. A leaked database backup is then a list of hashes and
 * not a set of working links, and lookup stays a single indexed equality test.
 *
 * The token itself is 32 bytes from `random_bytes()`, base64url-encoded. No customer id, no
 * licence id, no expiry encoded into it: everything a redemption needs is in the row, so nothing
 * about the URL can be tampered with in the first place.
 *
 * ## Redeeming does not authorise
 *
 * {@see redeem()} answers "which licence is this link for", and then hands that licence to
 * {@see Access} to be judged exactly as a signed-in customer would be. A token is a claim about
 * identity, not an entitlement — which is why revoking a licence stops its old links even though
 * nothing rewrote them.
 */
class Links extends Component
{
    /** Bytes of entropy in a token. 32 is 256 bits; the URL is 43 characters long. */
    private const TOKEN_BYTES = 32;

    /**
     * Mint a link for one file.
     *
     * The `maxUses` default is not one. A single-use link breaks the most ordinary thing a
     * customer does — click, get a browser error, click again — and turns it into a support
     * ticket. The protection that actually works is the *licence's* download limit, which counts
     * completed deliveries rather than clicks.
     */
    public function mintForFile(
        DownloadFile $file,
        Version $version,
        Download $download,
        ?License $license = null,
        array $config = [],
    ): LinkToken {
        return $this->mint(array_merge([
            'type' => LinkToken::TYPE_DOWNLOAD,
            'licenseId' => $license?->id,
            'downloadId' => $download->getCanonicalId(),
            'versionId' => $version->id,
            'fileId' => $file->id,
            'email' => $license?->email,
            'ttl' => $download->getEffectiveLinkTtl(),
        ], $config));
    }

    /** A link that lets a guest into the portal without an account. */
    public function mintForPortal(string $email, array $config = []): LinkToken
    {
        return $this->mint(array_merge([
            'type' => LinkToken::TYPE_PORTAL,
            'email' => $email,
            'ttl' => Plugin::getInstance()->getSettings()->portalTokenTtl,
        ], $config));
    }

    public function mint(array $config): LinkToken
    {
        $settings = Plugin::getInstance()->getSettings();
        $ttl = (int)($config['ttl'] ?? $settings->linkTtl);
        $token = $this->generateToken();

        $record = new TokenRecord();
        $record->tokenHash = $this->hash($token);
        $record->type = $config['type'] ?? LinkToken::TYPE_DOWNLOAD;
        $record->licenseId = $config['licenseId'] ?? null;
        $record->downloadId = $config['downloadId'] ?? null;
        $record->versionId = $config['versionId'] ?? null;
        $record->fileId = $config['fileId'] ?? null;
        $record->userId = $config['userId'] ?? null;
        $record->email = $config['email'] ?? null;
        $record->orderId = $config['orderId'] ?? null;
        $record->maxUses = $config['maxUses'] ?? null;
        $record->uses = 0;
        $record->expiryDate = Db::prepareDateForDb(
            (new DateTime('now', new DateTimeZone('UTC')))->modify(sprintf('+%d minutes', max(1, $ttl))),
        );

        // Bound to the address that asked for it, when the shop has asked for that. Off by default
        // because a customer whose phone changes cell mid-download is not an attacker.
        if (($config['bindIp'] ?? $settings->bindLinksToIp) && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            $record->ip = $config['ip'] ?? Craft::$app->getRequest()->getUserIP();
        }

        $record->save(false);

        $model = $this->rowToToken($record->getAttributes());
        $model->token = $token;

        return $model;
    }

    /** The URL a customer clicks. */
    public function urlFor(LinkToken $token): string
    {
        if ($token->token === null) {
            throw new \RuntimeException('A link URL can only be built from a token that was just minted.');
        }

        return UrlHelper::siteUrl('digits/download/' . $token->token);
    }

    /** Mint a link for a file and hand back the URL in one go, which is what every caller wants. */
    public function urlForFile(
        DownloadFile $file,
        Version $version,
        Download $download,
        ?License $license = null,
        array $config = [],
    ): string {
        return $this->urlFor($this->mintForFile($file, $version, $download, $license, $config));
    }

    public function getTokenByValue(string $token): ?LinkToken
    {
        $row = (new Query())
            ->from([Table::TOKENS])
            ->where(['tokenHash' => $this->hash($token)])
            ->one();

        return $row !== null ? $this->rowToToken($row) : null;
    }

    /**
     * Turn a token into a verdict.
     *
     * Two layers, and the order is the point. The token's own validity — is it known, has it
     * expired, has it been spent, was it bound to another address — is decided here. What it grants
     * is decided by {@see Access}, from the licence the row names. So a link minted last week for a
     * licence refunded yesterday is a valid token and a refused download, and the log says exactly
     * that.
     */
    public function redeem(string $token, ?User $user = null): array
    {
        $row = $this->getTokenByValue($token);

        if ($row === null || $row->type !== LinkToken::TYPE_DOWNLOAD) {
            return [null, AccessVerdict::deny(AccessVerdict::REASON_TOKEN_UNKNOWN)];
        }

        if ($row->getIsExpired()) {
            return [$row, AccessVerdict::deny(AccessVerdict::REASON_TOKEN_EXPIRED)];
        }

        if ($row->getIsSpent()) {
            return [$row, AccessVerdict::deny(AccessVerdict::REASON_TOKEN_SPENT)];
        }

        if ($row->ip !== null && !Craft::$app->getRequest()->getIsConsoleRequest()
            && $row->ip !== Craft::$app->getRequest()->getUserIP()) {
            return [$row, AccessVerdict::deny(AccessVerdict::REASON_TOKEN_IP)];
        }

        $plugin = Plugin::getInstance();
        $download = $row->downloadId !== null ? $plugin->downloads->getDownloadById($row->downloadId) : null;
        $license = $row->licenseId !== null ? $plugin->licenses->getLicenseById($row->licenseId) : null;

        // A token with no version pinned means "whatever is current" — which is what a permanent
        // link in a customer's account has to mean, or it would start handing out last year's
        // build the day after a release.
        $version = $row->versionId !== null
            ? $plugin->versions->getVersionById($row->versionId)
            : ($download !== null ? $plugin->versions->getCurrentVersion($download->getCanonicalId()) : null);

        $file = $row->fileId !== null
            ? $plugin->versions->getFileById($row->fileId)
            : ($version?->getFiles()[0] ?? null);

        $verdict = $plugin->access->checkFile($download, $version, $file, $license, $user, $row->email);

        return [$row, $verdict];
    }

    /** Count a redemption that actually delivered bytes. */
    public function spend(LinkToken $token): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                Table::TOKENS,
                ['uses' => new \yii\db\Expression('[[uses]] + 1')],
                ['id' => $token->id],
            )
            ->execute();

        $token->uses++;
    }

    /**
     * Kill every unspent link belonging to a licence.
     *
     * Called by {@see Licenses::revoke()}, and the reason this table exists at all.
     */
    public function revokeForLicense(int $licenseId): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::TOKENS, ['licenseId' => $licenseId])
            ->execute();
    }

    public function revokeForOrder(int $orderId): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::TOKENS, ['orderId' => $orderId])
            ->execute();
    }

    /**
     * Delete expired tokens.
     *
     * Run from garbage collection. Nothing depends on it — a redemption checks the date itself —
     * so this is housekeeping, and a site whose garbage collection never runs is slower rather
     * than less safe.
     */
    public function prune(): int
    {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::TOKENS, ['<', 'expiryDate', $now])
            ->execute();
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 32 random bytes, URL-safe.
     *
     * `random_bytes()` and nothing else. A token derived from an id, a timestamp or a hash of the
     * customer's email is a token somebody can compute.
     */
    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function rowToToken(array $row): LinkToken
    {
        return new LinkToken($row);
    }
}
