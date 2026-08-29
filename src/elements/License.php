<?php

declare(strict_types=1);

namespace justinholtweb\digits\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use justinholtweb\digits\elements\db\DownloadQuery;
use justinholtweb\digits\elements\db\LicenseQuery;
use justinholtweb\digits\models\Activation;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\LicenseRecord;

/**
 * The right to have something.
 *
 * A licence is Digits' single entitlement: one row means one person may download one set of files,
 * this many times, until this date, on this many machines. There is no separate "purchase" or
 * "grant" concept beside it, because there is only one question any of them would answer and two
 * tables answering it is how a shop ends up refunding an order and leaving the access behind.
 *
 * It is an element for the same reasons a job or an order is: the index with its sources and bulk
 * actions, search over customer and key, and a canonical CP URL that support can paste into a
 * ticket. It has no title — like a Commerce order, its identity is its key, which is why
 * {@see getUiLabel()} renders that instead.
 *
 * ## The status column is called `status` in the database and `licenseStatus` here
 *
 * `status` is `Element::getStatus()`, and a public property of that name silently shadows the
 * method that the element index, the status menu and every `->status()` query rely on. The column
 * keeps the obvious name; the query aliases it on the way in.
 *
 * ## Expiry is a comparison, not a flag
 *
 * A licence that runs out at midnight is expired at midnight, whether or not anything ran. So
 * {@see getStatus()} compares dates and {@see LicenseQuery::statusCondition()} does the same in
 * SQL. The nightly sweep writes `expired` into the column as well, but only so that exports and
 * reports read the same word the index shows — nothing ever depends on it having run.
 */
class License extends Element
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_PENDING = 'pending';

    public const SOURCE_ORDER = 'order';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CONSOLE = 'console';
    public const SOURCE_IMPORT = 'import';

    public ?string $licenseKey = null;
    public ?int $ownerId = null;
    public string $email = '';
    public ?string $customerName = null;
    public ?int $orderId = null;
    public ?int $lineItemId = null;
    public ?string $orderReference = null;
    public string $licenseStatus = self::STATUS_ACTIVE;
    public string $source = self::SOURCE_MANUAL;
    public ?DateTime $issuedDate = null;
    public ?DateTime $expiryDate = null;
    public ?int $downloadLimit = null;
    public int $downloadCount = 0;
    public ?int $activationLimit = null;
    public int $activationCount = 0;
    public ?DateTime $revokedDate = null;
    public ?string $revokedReason = null;
    public ?string $notes = null;

    /** @var Download[]|null */
    private ?array $_downloads = null;

    /** @var int[]|null Set by the editor; written on save. Null means "nobody touched them". */
    private ?array $_downloadIds = null;

    /** @var Activation[]|null */
    private ?array $_activations = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('digits', 'Licence');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('digits', 'licence');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('digits', 'Licences');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('digits', 'licences');
    }

    public static function refHandle(): ?string
    {
        return 'license';
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new LicenseQuery(static::class);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => ['label' => Craft::t('digits', 'Active'), 'color' => Color::Green],
            self::STATUS_PENDING => ['label' => Craft::t('digits', 'Pending'), 'color' => Color::Orange],
            self::STATUS_EXPIRED => ['label' => Craft::t('digits', 'Expired'), 'color' => Color::Amber],
            self::STATUS_REVOKED => ['label' => Craft::t('digits', 'Revoked'), 'color' => Color::Red],
            self::STATUS_DISABLED => ['label' => Craft::t('app', 'Disabled'), 'color' => Color::Gray],
        ];
    }

    /**
     * Columns Craft has to hydrate as dates rather than as strings.
     *
     * Missing one is not a type mismatch you notice — the element loads fine and every date
     * comparison against it silently becomes a string comparison.
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['issuedDate', 'expiryDate', 'revokedDate']);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl(sprintf('digits/licenses/%s', $this->getCanonicalId()));
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('digits/licenses');
    }

    public function getUiLabel(): string
    {
        return $this->licenseKey ?: Craft::t('digits', 'Licence {id}', ['id' => $this->id ?? '—']);
    }

    // Status
    // -------------------------------------------------------------------------

    public function getStatus(): ?string
    {
        if ($this->licenseStatus === self::STATUS_ACTIVE && $this->getIsExpired()) {
            return self::STATUS_EXPIRED;
        }

        return $this->licenseStatus;
    }

    public function getIsExpired(): bool
    {
        return $this->expiryDate !== null && $this->expiryDate->getTimestamp() <= time();
    }

    public function getIsRevoked(): bool
    {
        return $this->licenseStatus === self::STATUS_REVOKED;
    }

    /**
     * Whether this licence is live and has downloads left.
     *
     * A summary, not the verdict. Whether a *particular* file may be had is
     * {@see \justinholtweb\digits\services\Access}'s decision and nothing else's — a lapsed
     * licence is "not usable" by this measure and can still be entitled to every build released
     * while it was paid for, which is a distinction only the access service is in a position to
     * make.
     */
    public function getIsUsable(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE && !$this->getIsOverLimit();
    }

    public function getIsOverLimit(): bool
    {
        $limit = $this->getEffectiveDownloadLimit();

        return $limit > 0 && $this->downloadCount >= $limit;
    }

    /** Downloads left, or null when there is no limit. */
    public function getDownloadsRemaining(): ?int
    {
        $limit = $this->getEffectiveDownloadLimit();

        return $limit > 0 ? max(0, $limit - $this->downloadCount) : null;
    }

    public function getEffectiveDownloadLimit(): int
    {
        if ($this->downloadLimit !== null) {
            return $this->downloadLimit;
        }

        // The strictest of the downloads it covers. A bundle whose parts disagree has to obey the
        // tightest of them; the alternative is a bundle that launders a limit away.
        $limits = array_map(
            static fn(Download $download) => $download->getEffectiveDownloadLimit(),
            $this->getDownloads(),
        );

        $limits = array_filter($limits, static fn(int $limit) => $limit > 0);

        return $limits === [] ? 0 : min($limits);
    }

    public function getEffectiveActivationLimit(): int
    {
        if ($this->activationLimit !== null) {
            return $this->activationLimit;
        }

        $limits = array_map(
            static fn(Download $download) => $download->getEffectiveActivationLimit(),
            $this->getDownloads(),
        );

        $limits = array_filter($limits, static fn(int $limit) => $limit > 0);

        return $limits === [] ? 0 : min($limits);
    }

    public function getActivationsRemaining(): ?int
    {
        $limit = $this->getEffectiveActivationLimit();

        return $limit > 0 ? max(0, $limit - $this->activationCount) : null;
    }

    public function getDaysRemaining(): ?int
    {
        if ($this->expiryDate === null) {
            return null;
        }

        $diff = $this->expiryDate->getTimestamp() - time();

        return $diff <= 0 ? 0 : (int)ceil($diff / 86400);
    }

    /**
     * Whether a version released on this date is covered.
     *
     * The "one year of updates" model, and the reason a lapsed licence is not a dead licence: the
     * files it paid for stay available forever, and only the ones released afterwards are behind
     * the renewal.
     */
    public function coversVersionReleasedOn(?DateTime $released): bool
    {
        if ($this->expiryDate === null || $released === null) {
            return true;
        }

        return $released->getTimestamp() <= $this->expiryDate->getTimestamp();
    }

    // Relationships
    // -------------------------------------------------------------------------

    /** @return Download[] */
    public function getDownloads(): array
    {
        if ($this->_downloads === null) {
            if ($this->id === null) {
                return [];
            }

            /** @var DownloadQuery $query */
            $query = Download::find();
            $this->_downloads = $query->licenseId($this->getCanonicalId())->status(null)->all();
        }

        return $this->_downloads;
    }

    /** @return int[] */
    public function getDownloadIds(): array
    {
        if ($this->_downloadIds !== null) {
            return $this->_downloadIds;
        }

        return array_map(static fn(Download $download) => (int)$download->id, $this->getDownloads());
    }

    public function setDownloadIds(array $ids): void
    {
        $this->_downloadIds = array_values(array_unique(array_map('intval', array_filter($ids))));
        $this->_downloads = null;
    }

    public function coversDownload(int $downloadId): bool
    {
        return in_array($downloadId, $this->getDownloadIds(), true);
    }

    /** @return Activation[] */
    public function getActivations(bool $activeOnly = false): array
    {
        if ($this->_activations === null) {
            $this->_activations = $this->id !== null
                ? Plugin::getInstance()->activations->getActivationsForLicense($this->getCanonicalId())
                : [];
        }

        if (!$activeOnly) {
            return $this->_activations;
        }

        return array_values(array_filter($this->_activations, static fn(Activation $a) => $a->getIsActive()));
    }

    public function getOwner(): ?User
    {
        return $this->ownerId !== null ? Craft::$app->getUsers()->getUserById($this->ownerId) : null;
    }

    public function getCustomerLabel(): string
    {
        if ($this->customerName !== null && trim($this->customerName) !== '') {
            return $this->customerName;
        }

        return $this->email;
    }

    /**
     * The order, when Commerce is installed and the order is still there.
     *
     * Typed loosely and guarded, because this plugin runs on sites with no Commerce at all and a
     * return type naming a Commerce class would be a fatal error on every one of them.
     */
    public function getOrder(): mixed
    {
        if ($this->orderId === null || !Plugin::commerceIsReady()) {
            return null;
        }

        return \craft\commerce\elements\Order::find()->id($this->orderId)->status(null)->one();
    }

    // Saving
    // -------------------------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['email'], 'required'];
        $rules[] = [['email'], 'email'];
        $rules[] = [['licenseKey'], 'string', 'max' => 128];
        $rules[] = [['downloadLimit', 'activationLimit'], 'integer', 'min' => 0];
        $rules[] = [['licenseStatus'], 'in', 'range' => [
            self::STATUS_ACTIVE,
            self::STATUS_EXPIRED,
            self::STATUS_REVOKED,
            self::STATUS_DISABLED,
            self::STATUS_PENDING,
        ]];
        // `skipOnEmpty => false`: an empty array is "empty" to Yii, and the rule exists to reject
        // precisely that.
        $rules[] = [['downloadIds'], 'validateDownloads', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateDownloads(): void
    {
        if ($this->getDownloadIds() === []) {
            $this->addError('downloadIds', Craft::t('digits', 'A licence has to cover at least one download.'));
        }
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($this->issuedDate === null) {
            $this->issuedDate = new DateTime();
        }

        if ($isNew && $this->licenseKey === null) {
            $this->licenseKey = Plugin::getInstance()->keys->generate();
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new LicenseRecord() : LicenseRecord::findOne($this->id);

            if ($record === null) {
                $record = new LicenseRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->licenseKey = $this->licenseKey;
            $record->ownerId = $this->ownerId;
            $record->email = $this->email;
            $record->customerName = $this->customerName;
            $record->orderId = $this->orderId;
            $record->lineItemId = $this->lineItemId;
            $record->orderReference = $this->orderReference;
            $record->status = $this->licenseStatus;
            $record->source = $this->source;
            $record->issuedDate = Db::prepareDateForDb($this->issuedDate);
            $record->expiryDate = Db::prepareDateForDb($this->expiryDate);
            $record->downloadLimit = $this->downloadLimit;
            $record->downloadCount = $this->downloadCount;
            $record->activationLimit = $this->activationLimit;
            $record->activationCount = $this->activationCount;
            $record->revokedDate = Db::prepareDateForDb($this->revokedDate);
            $record->revokedReason = $this->revokedReason;
            $record->notes = $this->notes;
            $record->save(false);

            // Null means the editor never touched them — a resave, a counter increment, a status
            // sweep. Writing an empty set in that case would quietly unlink every licence that
            // anything ever re-saved, which is the same thing as revoking it without saying so.
            if ($this->_downloadIds !== null) {
                Plugin::getInstance()->licenses->setDownloadsForLicense((int)$this->id, $this->_downloadIds);
                $this->_downloads = null;
            }
        }

        parent::afterSave($isNew);
    }

    // Search
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['licenseKey', 'email', 'customerName', 'orderReference'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('digits', 'All licences'),
                'defaultSort' => ['issuedDate', 'desc'],
            ],
            ['heading' => Craft::t('digits', 'Status')],
            [
                'key' => 'status:active',
                'label' => Craft::t('digits', 'Active'),
                'criteria' => ['status' => self::STATUS_ACTIVE],
            ],
            [
                'key' => 'status:expired',
                'label' => Craft::t('digits', 'Expired'),
                'criteria' => ['status' => self::STATUS_EXPIRED],
                'defaultSort' => ['expiryDate', 'desc'],
            ],
            [
                'key' => 'status:revoked',
                'label' => Craft::t('digits', 'Revoked'),
                'criteria' => ['status' => self::STATUS_REVOKED],
            ],
            ['heading' => Craft::t('digits', 'Attention')],
            [
                'key' => 'expiring',
                'label' => Craft::t('digits', 'Expiring within 30 days'),
                'criteria' => ['status' => self::STATUS_ACTIVE, 'expiringWithin' => 30],
                'defaultSort' => ['expiryDate', 'asc'],
            ],
            [
                'key' => 'activated',
                'label' => Craft::t('digits', 'In use'),
                'criteria' => ['hasActivations' => true],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'licenseKey' => ['label' => Craft::t('digits', 'Key')],
            'customer' => ['label' => Craft::t('digits', 'Customer')],
            'downloads' => ['label' => Craft::t('digits', 'Downloads')],
            'order' => ['label' => Craft::t('digits', 'Order')],
            'issuedDate' => ['label' => Craft::t('digits', 'Issued')],
            'expiryDate' => ['label' => Craft::t('digits', 'Expires')],
            'usage' => ['label' => Craft::t('digits', 'Downloads used')],
            'activations' => ['label' => Craft::t('digits', 'Activations')],
            'source' => ['label' => Craft::t('digits', 'Source')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['licenseKey', 'customer', 'downloads', 'expiryDate', 'usage', 'activations'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'digits_licenses.issuedDate' => Craft::t('digits', 'Issued'),
            'digits_licenses.expiryDate' => Craft::t('digits', 'Expires'),
            'digits_licenses.email' => Craft::t('digits', 'Customer'),
            'digits_licenses.downloadCount' => Craft::t('digits', 'Downloads used'),
            'digits_licenses.activationCount' => Craft::t('digits', 'Activations'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'licenseKey' => Html::tag('code', Html::encode((string)$this->licenseKey), ['class' => 'small']),
            'customer' => Html::encode($this->getCustomerLabel()),
            'downloads' => Html::encode(implode(', ', array_map(
                static fn(Download $download) => (string)$download->title,
                $this->getDownloads(),
            ))),
            'order' => $this->orderHtml(),
            'usage' => $this->usageHtml(),
            'activations' => $this->activationHtml(),
            'expiryDate' => $this->expiryHtml(),
            'source' => Html::encode($this->getSourceLabel()),
            default => parent::attributeHtml($attribute),
        };
    }

    public function getSourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_ORDER => Craft::t('digits', 'Order'),
            self::SOURCE_CONSOLE => Craft::t('digits', 'Console'),
            self::SOURCE_IMPORT => Craft::t('digits', 'Import'),
            default => Craft::t('digits', 'Added by hand'),
        };
    }

    private function orderHtml(): string
    {
        if ($this->orderReference === null && $this->orderId === null) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        $label = $this->orderReference ?: '#' . $this->orderId;

        if ($this->orderId !== null && Plugin::commerceIsReady()) {
            return Html::a(Html::encode($label), UrlHelper::cpUrl('commerce/orders/' . $this->orderId));
        }

        return Html::encode($label);
    }

    private function usageHtml(): string
    {
        $limit = $this->getEffectiveDownloadLimit();

        if ($limit === 0) {
            return Html::encode((string)$this->downloadCount);
        }

        $label = sprintf('%d / %d', $this->downloadCount, $limit);

        // Craft's status indicator is a dot and a label as *siblings*. Text placed inside
        // `.status` is squeezed into a fixed-size circle and wraps one letter per line.
        if ($this->downloadCount >= $limit) {
            return Html::tag('span', '', ['class' => ['status', 'red']]) . Html::tag('span', $label);
        }

        return Html::encode($label);
    }

    private function activationHtml(): string
    {
        $limit = $this->getEffectiveActivationLimit();
        $label = $limit > 0
            ? sprintf('%d / %d', $this->activationCount, $limit)
            : (string)$this->activationCount;

        return Html::encode($label);
    }

    private function expiryHtml(): string
    {
        if ($this->expiryDate === null) {
            return Html::tag('span', Craft::t('digits', 'Never'), ['class' => 'light']);
        }

        $formatted = Craft::$app->getFormatter()->asDate($this->expiryDate, 'short');
        $days = $this->getDaysRemaining();

        if ($days === 0) {
            return Html::tag('span', Html::encode($formatted), ['class' => 'error']);
        }

        if ($days !== null && $days <= 30) {
            return Html::tag('span', '', ['class' => ['status', 'orange']]) . Html::tag('span', Html::encode($formatted));
        }

        return Html::encode($formatted);
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_LICENSES);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_LICENSES);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE_LICENSES);
    }
}
