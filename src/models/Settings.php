<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use Craft;
use craft\base\Model;

/**
 * Digits' site-wide settings.
 *
 * Every default here is a *fallback*, not a rule: a download that leaves `downloadLimit` null gets
 * this one, and a shop that changes its mind changes it once. Nothing in this class is `required`
 * — a settings model with a required attribute cannot be saved by the installer, and the plugin
 * then cannot be installed at all. See `[[craft-plugin-gotchas]]`.
 */
class Settings extends Model
{
    public const REVOKE_NEVER = 'never';
    public const REVOKE_FULL = 'full';
    public const REVOKE_ANY = 'any';

    public const DELIVERY_PHP = '';
    public const DELIVERY_XSENDFILE = 'x-sendfile';
    public const DELIVERY_XACCEL = 'x-accel-redirect';

    public const ISSUE_ON_COMPLETE = 'complete';
    public const ISSUE_ON_PAID = 'paid';
    public const ISSUE_ON_STATUS = 'status';

    // Downloads
    // -------------------------------------------------------------------------

    /**
     * How long a minted download link stays good for, in minutes.
     *
     * Short enough that a link forwarded to a forum is dead by the time anybody clicks it, long
     * enough that somebody who opens the email on the train and downloads at home still gets the
     * file. Fifteen minutes is neither of those; a day is the honest compromise, and the *limit*
     * is what actually protects the file.
     */
    public int $linkTtl = 1440;

    /** Downloads allowed per licence. 0 is unlimited, which is the right answer for most shops. */
    public int $downloadLimit = 0;

    /** How long access lasts, in days. 0 means it never expires. */
    public int $accessDays = 0;

    /** Activations allowed per licence. 0 is unlimited. */
    public int $activationLimit = 1;

    /**
     * Whether a link only works from the address it was minted for.
     *
     * Off by default and it should stay off for most shops: mobile networks change a visitor's IP
     * mid-session, so this turns "my download stopped working" into a support queue. Worth it for
     * expensive files and nothing else.
     */
    public bool $bindLinksToIp = false;

    /** Serve every file as an attachment, even the ones a browser could display. */
    public bool $forceDownload = true;

    /**
     * Hand the bytes to the web server instead of pushing them through PHP.
     *
     * Empty means PHP does the work, which always functions. The other two are much faster and
     * only work when the server has been told about the paths in {@see $internalPaths}.
     */
    public string $fileDeliveryMethod = self::DELIVERY_PHP;

    /** `/local/path => /internal-location` pairs for the X-Accel-Redirect handoff. */
    public array $internalPaths = [];

    /** Keep download log rows this many days. 0 keeps them forever. */
    public int $logRetentionDays = 365;

    // Licences
    // -------------------------------------------------------------------------

    /** The shape of a key. `X` is replaced by a character from the alphabet; everything else is literal. */
    public string $keyFormat = 'XXXX-XXXX-XXXX-XXXX';

    /**
     * The alphabet keys are drawn from.
     *
     * No `I`, `O`, `0` or `1`: every one of those is read aloud wrong down a telephone, and a
     * licence key is a thing people read aloud down telephones.
     */
    public string $keyAlphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Prefixed to every generated key, e.g. `ACME-`. */
    public string $keyPrefix = '';

    /** When a purchase turns into a licence. */
    public string $issueOn = self::ISSUE_ON_COMPLETE;

    /** Order statuses that also issue, when {@see $issueOn} is `status`. */
    public array $issueOnStatuses = [];

    /**
     * Whether buying three of something gives three keys or one key good for three machines.
     *
     * Off, because "three of this" from one company means one licence with three seats far more
     * often than it means three separate customers. A shop selling gift copies turns it on.
     */
    public bool $licensePerQuantity = false;

    /** What a refund does to the licences that came out of that order. */
    public string $revokeOnRefund = self::REVOKE_FULL;

    /** Order statuses that revoke the order's licences the moment they are applied. */
    public array $revokeOnStatuses = ['cancelled'];

    /** Whether the customer is emailed their licence and links when it is issued. */
    public bool $notifyOnIssue = true;

    /** Whether licensees are emailed when a new version is released. */
    public bool $notifyOnRelease = true;

    /** Whether the activation API is switched on at all. */
    public bool $enableActivationApi = true;

    // Portal
    // -------------------------------------------------------------------------

    /** Whether the front-end customer portal is served. */
    public bool $enablePortal = true;

    /** Where the portal lives on the front end. */
    public string $portalPath = 'account/downloads';

    /**
     * How long a guest's emailed portal link lasts, in minutes.
     *
     * A guest checkout has no account to log into, so this link *is* the customer's access to
     * everything they bought. A week is long enough to be useful and short enough that a mailbox
     * breached next year is not a shop breach.
     */
    public int $portalTokenTtl = 10080;

    /** Let a guest ask for a fresh portal link by entering the email they ordered with. */
    public bool $allowPortalRequests = true;

    // Invoicing
    // -------------------------------------------------------------------------

    /** Whether Digits raises VAT invoices from Commerce orders at all. */
    public bool $enableInvoicing = false;

    public string $invoiceSeries = 'default';

    /** Tokens: `{series}`, `{year}`, `{month}`, `{number}`. */
    public string $invoiceNumberFormat = 'INV-{year}-{number}';

    public int $invoiceNumberPadding = 5;

    /** How often the invoice counter restarts: `never`, `yearly` or `monthly`. */
    public string $invoiceNumberReset = 'yearly';

    public int $invoiceStartNumber = 1;

    /** The seller. Printed on every invoice, and the country the place-of-supply rules turn on. */
    public string $sellerName = '';
    public string $sellerAddress = '';
    public string $sellerCountry = '';
    public string $sellerVatId = '';
    public string $sellerRegistration = '';

    /** Extra wording printed at the foot of every invoice. */
    public string $invoiceFooter = '';

    /**
     * Whether a VAT ID is checked against VIES before it earns the reverse charge.
     *
     * Off by default, and deliberately: this is an outbound request to the Commission's service
     * during somebody's checkout, and that service is famously down. With it off, Digits still
     * checks the country prefix, the length and the national checksum — which catches typos, and
     * which is *not* the same evidence as a VIES confirmation. The invoice records which of the
     * two it got.
     */
    public bool $validateVatIds = false;

    /** Seconds to wait on VIES before giving up and falling back to the offline check. */
    public int $viesTimeout = 5;

    /** Days a VIES answer is trusted before it is asked again. */
    public int $vatIdCacheDays = 90;

    /**
     * A request header carrying the visitor's country, set by a CDN.
     *
     * The second piece of non-contradictory evidence the place-of-supply rules ask for. Digits
     * will not do a geo-IP lookup of its own — that is an outbound request and a database to keep
     * current — so if the CDN does not set one of these, the invoice records that it had one
     * piece of evidence and says so.
     */
    public string $countryHeader = 'CF-IPCountry';

    public function defineRules(): array
    {
        return [
            [['linkTtl', 'portalTokenTtl'], 'integer', 'min' => 1],
            [['downloadLimit', 'accessDays', 'activationLimit', 'logRetentionDays', 'invoiceNumberPadding', 'vatIdCacheDays'], 'integer', 'min' => 0],
            [['viesTimeout'], 'integer', 'min' => 1, 'max' => 60],
            [['invoiceStartNumber'], 'integer', 'min' => 1],
            [['keyFormat'], 'match', 'pattern' => '/X/', 'message' => Craft::t('digits', 'The key format needs at least one X.')],
            [['keyAlphabet'], 'string', 'min' => 8],
            [['revokeOnRefund'], 'in', 'range' => [self::REVOKE_NEVER, self::REVOKE_FULL, self::REVOKE_ANY]],
            [['fileDeliveryMethod'], 'in', 'range' => [self::DELIVERY_PHP, self::DELIVERY_XSENDFILE, self::DELIVERY_XACCEL]],
            [['issueOn'], 'in', 'range' => [self::ISSUE_ON_COMPLETE, self::ISSUE_ON_PAID, self::ISSUE_ON_STATUS]],
            [['invoiceNumberReset'], 'in', 'range' => ['never', 'yearly', 'monthly']],
            [['internalPaths', 'issueOnStatuses', 'revokeOnStatuses'], 'safe'],
            [['invoiceNumberFormat'], 'match', 'pattern' => '/\{number\}/', 'message' => Craft::t('digits', 'The invoice number format needs a {number} token.')],
            [['portalPath'], 'match', 'pattern' => '/^[a-zA-Z0-9\-_\/]*$/', 'message' => Craft::t('digits', 'The portal path can only contain letters, numbers, slashes, hyphens and underscores.')],
        ];
    }

    /** `/local/path => /internal-location`, normalised and with the empty rows dropped. */
    public function getInternalPathMap(): array
    {
        $map = [];

        foreach ($this->internalPaths as $row) {
            $path = trim((string)($row['path'] ?? ''));
            $location = trim((string)($row['location'] ?? ''));

            if ($path !== '' && $location !== '') {
                $map[$path] = '/' . ltrim($location, '/');
            }
        }

        return $map;
    }

    public function getPortalUrl(array $params = []): string
    {
        return \craft\helpers\UrlHelper::siteUrl($this->portalPath, $params);
    }
}
