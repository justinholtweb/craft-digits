<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\models\SystemMessage;
use DateTime;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\NoticeRecord;
use Throwable;

/**
 * The three emails Digits sends.
 *
 * They are **Craft system messages**, not templates of Digits' own, which means a site edits them
 * where it edits every other email it sends, translates them per language the way Craft already
 * does, and never has to fork a plugin template to change a sentence.
 *
 * Nothing here throws. An email that fails must not take down the order completion, the release, or
 * the customer's own request for a link — the failure goes to the log, and the licence, the version
 * and the portal all still exist.
 */
class Notifications extends Component
{
    public const MESSAGE_LICENSE_ISSUED = 'digits_license_issued';
    public const MESSAGE_VERSION_RELEASED = 'digits_version_released';
    public const MESSAGE_PORTAL_LINK = 'digits_portal_link';

    /** @return SystemMessage[] */
    public static function systemMessages(): array
    {
        return [
            new SystemMessage([
                'key' => self::MESSAGE_LICENSE_ISSUED,
                'heading' => Craft::t('digits', 'When a customer’s downloads are ready:'),
                'subject' => Craft::t('digits', 'Your downloads are ready'),
                'body' => Craft::t('digits', "Hello,\n\nYour downloads are ready.\n\n{% for license in licenses %}{% for download in license.downloads %}- {{ download.title }}{% endfor %}\n{% if license.licenseKey %}  Licence key: {{ license.licenseKey }}\n{% endif %}{% endfor %}\n\nYou can download your files here, at any time:\n{{ portalUrl }}\n\nThank you."),
            ]),
            new SystemMessage([
                'key' => self::MESSAGE_VERSION_RELEASED,
                'heading' => Craft::t('digits', 'When a new version is released to licence holders:'),
                'subject' => Craft::t('digits', '{download} {version} is available'),
                'body' => Craft::t('digits', "Hello,\n\n{{ download.title }} {{ version.version }} is now available.\n\n{% if version.releaseNotes %}{{ version.releaseNotes }}\n\n{% endif %}Download it here:\n{{ portalUrl }}\n\nThank you."),
            ]),
            new SystemMessage([
                'key' => self::MESSAGE_PORTAL_LINK,
                'heading' => Craft::t('digits', 'When somebody asks for a link to their downloads:'),
                'subject' => Craft::t('digits', 'Your downloads'),
                'body' => Craft::t('digits', "Hello,\n\nHere is a link to everything you have bought from us:\n{{ portalUrl }}\n\nThe link works until {{ expiry }}.\n\nIf you did not ask for this, you can ignore it."),
            ]),
        ];
    }

    /**
     * Tell a customer their files are ready.
     *
     * One email for the whole order rather than one per licence: an order with four products
     * should not produce four emails, and the portal link in it reaches all of them anyway.
     *
     * @param License[] $licenses
     */
    public function sendLicenseIssued(mixed $order, array $licenses): bool
    {
        if ($licenses === []) {
            return false;
        }

        $email = $licenses[0]->email;

        return $this->send($email, self::MESSAGE_LICENSE_ISSUED, [
            'order' => $order,
            'licenses' => $licenses,
            'portalUrl' => $this->portalUrlFor($email),
        ]);
    }

    /**
     * Tell one licensee about a release.
     *
     * The notice row is written **before** the send and carries a unique index on
     * (version, licence). That is what makes the notifier safe to re-run: a second attempt collides
     * on the index instead of mailing everybody a second time, and the row records a failure as a
     * failure rather than silently leaving the customer to be mailed again next week.
     */
    public function sendVersionReleased(Version $version, Download $download, License $license): bool
    {
        if (!Edition::allowsUpdateNotices(Plugin::getInstance()->isPro())) {
            return false;
        }

        $record = new NoticeRecord();
        $record->versionId = $version->id;
        $record->licenseId = $license->id;
        $record->email = $license->email;
        $record->status = 'sent';

        try {
            $record->save(false);
        } catch (Throwable) {
            // Already notified. Not an error — it is the guard doing its job.
            return false;
        }

        $sent = $this->send($license->email, self::MESSAGE_VERSION_RELEASED, [
            'download' => $download,
            'version' => $version,
            'license' => $license,
            'portalUrl' => $this->portalUrlFor($license->email),
        ], ['download' => $download->title, 'version' => $version->version]);

        if (!$sent) {
            $record->status = 'failed';
            $record->save(false);
        }

        Plugin::getInstance()->log->write(Log::EVENT_NOTICE, [
            'licenseId' => $license->id,
            'downloadId' => $download->id,
            'versionId' => $version->id,
            'email' => $license->email,
            'reason' => $sent ? null : 'send-failed',
        ]);

        return $sent;
    }

    /** Send a guest a way back into their own purchases. */
    public function sendPortalLink(string $email): bool
    {
        $plugin = Plugin::getInstance();
        $licenses = $plugin->licenses->getLicensesForEmail($email);

        // Nothing to send. The caller still tells the visitor "if that address has orders, a link
        // is on its way" — saying otherwise turns this form into a way of asking whether somebody
        // is a customer.
        if ($licenses === []) {
            return false;
        }

        $token = $plugin->links->mintForPortal($email);
        $url = $plugin->getSettings()->getPortalUrl(['t' => $token->token]);

        return $this->send($email, self::MESSAGE_PORTAL_LINK, [
            'portalUrl' => $url,
            'expiry' => Craft::$app->getFormatter()->asDatetime($token->expiryDate ?? new DateTime(), 'short'),
        ]);
    }

    /** Who has been told about a release already. */
    public function noticeCount(int $versionId): int
    {
        return (int)(new \craft\db\Query())
            ->from([Table::NOTICES])
            ->where(['versionId' => $versionId])
            ->count();
    }

    /**
     * The URL a customer follows to their downloads.
     *
     * A signed-in customer gets the plain portal URL; a guest gets one carrying a token, because
     * a guest has no session to recognise them by and an email is the only channel where handing
     * one out is safe.
     */
    private function portalUrlFor(string $email): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enablePortal || !Edition::allowsPortal($plugin->isPro())) {
            return \craft\helpers\UrlHelper::siteUrl();
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);

        if ($user !== null) {
            return $settings->getPortalUrl();
        }

        $token = $plugin->links->mintForPortal($email);

        return $settings->getPortalUrl(['t' => $token->token]);
    }

    private function send(string $to, string $key, array $variables = [], array $subjectVariables = []): bool
    {
        try {
            $mailer = Craft::$app->getMailer();
            $message = $mailer->composeFromKey($key, array_merge($variables, $subjectVariables));

            return $message->setTo($to)->send();
        } catch (Throwable $e) {
            Craft::warning(sprintf('Could not send the Digits “%s” email to %s: %s', $key, $to, $e->getMessage()), Plugin::LOG_CATEGORY);

            return false;
        }
    }
}
