<?php

declare(strict_types=1);

namespace justinholtweb\digits\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use justinholtweb\digits\elements\db\DownloadQuery;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\DownloadRecord;

/**
 * A thing that can be downloaded, and the rules for having it.
 *
 * An element rather than a settings row, because a shop's catalogue of files needs the plumbing
 * Craft already has and nobody should rewrite: a field layout so the description and the artwork
 * are the site's own fields, search, relations, the index with its sources and its bulk actions,
 * and the trash.
 *
 * ## What is a column and what is a field
 *
 * A column is something *Digits* has to reason about — the SKU it is matched by, who may have it,
 * how many times, for how long, whether a purchase mints a key. Those decide queries and verdicts,
 * so they cannot live in a field layout a site is free to strip.
 *
 * Everything the customer reads — the description, the screenshots, the changelog blurb, the
 * system requirements — is the field layout's, and Digits has no opinion about it.
 *
 * ## No URIs
 *
 * A download is deliberately not a page. The page is the shop's — a Commerce product, an entry, a
 * docs section — and the download is attached to it with a relation field. Giving downloads URIs
 * of their own would create a second, thinner product page that nobody asked for and that every
 * site would then have to hide.
 */
class Download extends Element
{
    public const ACCESS_LICENSED = 'licensed';
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_LOGIN = 'login';
    public const ACCESS_GROUPS = 'groups';

    public ?string $sku = null;
    public string $accessType = self::ACCESS_LICENSED;
    public ?int $downloadLimit = null;
    public ?int $accessDays = null;
    public ?int $linkTtl = null;
    public ?int $activationLimit = null;
    public bool $issuesLicense = true;
    public bool $gateUpdatesOnExpiry = true;
    public bool $notifyOnRelease = true;
    public ?int $sortOrder = null;

    /** @var int[] */
    private array $_groupIds = [];

    /** @var Version[]|null */
    private ?array $_versions = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('digits', 'Download');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('digits', 'download');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('digits', 'Downloads');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('digits', 'downloads');
    }

    public static function refHandle(): ?string
    {
        return 'download';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    public static function find(): ElementQueryInterface
    {
        return new DownloadQuery(static::class);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl(sprintf('digits/downloads/%s', $this->getCanonicalId()));
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('digits/downloads');
    }

    public function getUiLabel(): string
    {
        return $this->title ?: Craft::t('digits', 'Untitled download');
    }

    // Access rules
    // -------------------------------------------------------------------------

    /** @return int[] */
    public function getGroupIds(): array
    {
        return $this->_groupIds;
    }

    /**
     * Accepts the JSON the column holds or an array from the editor.
     *
     * The column-shaped case is not optional: Craft hands the raw database row to the element's
     * constructor, so a `groupIds` column with no setter throws `UnknownPropertyException` — and
     * only when the element is read back from the database, never straight after a save.
     */
    public function setGroupIds(mixed $value): void
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        $this->_groupIds = array_values(array_unique(array_map('intval', array_filter((array)$value))));
    }

    public function getRequiresLicense(): bool
    {
        return $this->accessType === self::ACCESS_LICENSED;
    }

    /** Downloads permitted per licence. 0 is unlimited. */
    public function getEffectiveDownloadLimit(): int
    {
        return $this->downloadLimit ?? Plugin::getInstance()->getSettings()->downloadLimit;
    }

    /** Days a licence lasts. 0 is forever. */
    public function getEffectiveAccessDays(): int
    {
        return $this->accessDays ?? Plugin::getInstance()->getSettings()->accessDays;
    }

    /** Minutes a minted link stays good for. */
    public function getEffectiveLinkTtl(): int
    {
        return $this->linkTtl ?: Plugin::getInstance()->getSettings()->linkTtl;
    }

    /** Seats per licence. 0 is unlimited. */
    public function getEffectiveActivationLimit(): int
    {
        return $this->activationLimit ?? Plugin::getInstance()->getSettings()->activationLimit;
    }

    // Versions
    // -------------------------------------------------------------------------

    /** @return Version[] Newest release first. */
    public function getVersions(bool $liveOnly = true): array
    {
        if ($this->_versions === null) {
            $this->_versions = $this->id !== null
                ? Plugin::getInstance()->versions->getVersionsForDownload($this->getCanonicalId())
                : [];
        }

        if (!$liveOnly) {
            return $this->_versions;
        }

        return array_values(array_filter($this->_versions, static fn(Version $v) => $v->getIsLive()));
    }

    public function getCurrentVersion(): ?Version
    {
        foreach ($this->getVersions() as $version) {
            if ($version->isCurrent) {
                return $version;
            }
        }

        // A download whose current flag went missing — a version deleted, an import — still has to
        // hand somebody a file. The newest live release is the only sensible fallback.
        return $this->getVersions()[0] ?? null;
    }

    public function getVersionByNumber(string $number): ?Version
    {
        foreach ($this->getVersions(false) as $version) {
            if ($version->version === $number) {
                return $version;
            }
        }

        return null;
    }

    public function getHasFiles(): bool
    {
        return $this->getCurrentVersion()?->getFiles() !== [];
    }

    // Licences
    // -------------------------------------------------------------------------

    public function getLicenses(): \justinholtweb\digits\elements\db\LicenseQuery
    {
        /** @var \justinholtweb\digits\elements\db\LicenseQuery $query */
        $query = License::find();

        return $query->downloadId($this->getCanonicalId());
    }

    public function getLicenseCount(): int
    {
        return $this->id !== null ? (int)$this->getLicenses()->status(null)->count() : 0;
    }

    // Saving
    // -------------------------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['sku'], 'string', 'max' => 255];
        $rules[] = [['downloadLimit', 'accessDays', 'linkTtl', 'activationLimit'], 'integer', 'min' => 0];
        $rules[] = [['accessType'], 'in', 'range' => [
            self::ACCESS_LICENSED,
            self::ACCESS_PUBLIC,
            self::ACCESS_LOGIN,
            self::ACCESS_GROUPS,
        ]];
        // `skipOnEmpty => false` matters and is easy to miss: Yii skips an inline validator when
        // the attribute is empty, and an empty array counts as empty — so the rule whose entire job
        // is to reject "nothing chosen" would be skipped in exactly that case.
        $rules[] = [['groupIds'], 'validateGroups', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateGroups(): void
    {
        if ($this->accessType === self::ACCESS_GROUPS && $this->_groupIds === []) {
            $this->addError('groupIds', Craft::t('digits', 'Choose at least one user group.'));
        }
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new DownloadRecord() : DownloadRecord::findOne($this->id);

            if ($record === null) {
                // A restored element, or one whose row went missing. Written back rather than
                // failing the save and leaving a download with no rules at all — which, for the
                // default access type, would be a download nobody can ever have.
                $record = new DownloadRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->sku = $this->sku;
            $record->accessType = $this->accessType;
            $record->groupIds = $this->_groupIds !== [] ? Json::encode($this->_groupIds) : null;
            $record->downloadLimit = $this->downloadLimit;
            $record->accessDays = $this->accessDays;
            $record->linkTtl = $this->linkTtl;
            $record->activationLimit = $this->activationLimit;
            $record->issuesLicense = $this->issuesLicense;
            $record->gateUpdatesOnExpiry = $this->gateUpdatesOnExpiry;
            $record->notifyOnRelease = $this->notifyOnRelease;
            $record->sortOrder = $this->sortOrder;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    // Search
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'sku'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('digits', 'All downloads'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('digits', 'Access')],
            [
                'key' => 'access:licensed',
                'label' => Craft::t('digits', 'Licensed'),
                'criteria' => ['accessType' => self::ACCESS_LICENSED],
            ],
            [
                'key' => 'access:gated',
                'label' => Craft::t('digits', 'Gated by account'),
                'criteria' => ['accessType' => [self::ACCESS_LOGIN, self::ACCESS_GROUPS]],
            ],
            [
                'key' => 'access:public',
                'label' => Craft::t('digits', 'Public'),
                'criteria' => ['accessType' => self::ACCESS_PUBLIC],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'sku' => ['label' => Craft::t('digits', 'SKU')],
            'accessType' => ['label' => Craft::t('digits', 'Access')],
            'currentVersion' => ['label' => Craft::t('digits', 'Current version')],
            'versionCount' => ['label' => Craft::t('digits', 'Versions')],
            'licenseCount' => ['label' => Craft::t('digits', 'Licences')],
            'downloadLimit' => ['label' => Craft::t('digits', 'Download limit')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['sku', 'accessType', 'currentVersion', 'licenseCount'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'digits_downloads.sku' => Craft::t('digits', 'SKU'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'sku' => $this->sku ? Html::tag('code', Html::encode($this->sku), ['class' => 'small light']) : '',
            'accessType' => Html::encode($this->getAccessLabel()),
            'currentVersion' => $this->currentVersionHtml(),
            'versionCount' => (string)count($this->getVersions(false)),
            'licenseCount' => $this->licenseCountHtml(),
            'downloadLimit' => $this->getEffectiveDownloadLimit() > 0
                ? (string)$this->getEffectiveDownloadLimit()
                : Html::tag('span', Craft::t('digits', 'Unlimited'), ['class' => 'light']),
            default => parent::attributeHtml($attribute),
        };
    }

    public function getAccessLabel(): string
    {
        return match ($this->accessType) {
            self::ACCESS_PUBLIC => Craft::t('digits', 'Anyone'),
            self::ACCESS_LOGIN => Craft::t('digits', 'Signed-in users'),
            self::ACCESS_GROUPS => Craft::t('digits', 'Chosen user groups'),
            default => Craft::t('digits', 'Licence holders'),
        };
    }

    private function currentVersionHtml(): string
    {
        $version = $this->getCurrentVersion();

        if ($version === null) {
            // Not a neutral empty cell: a download with no released version cannot be delivered,
            // and the index is where somebody notices before a customer does.
            return Html::tag('span', '', ['class' => ['status', 'orange']])
                . Html::tag('span', Craft::t('digits', 'No release'), ['class' => 'light']);
        }

        return Html::tag('code', Html::encode($version->version), ['class' => 'small']);
    }

    private function licenseCountHtml(): string
    {
        $count = $this->getLicenseCount();

        if ($count === 0) {
            return Html::tag('span', '0', ['class' => 'light']);
        }

        return Html::a((string)$count, UrlHelper::cpUrl('digits/licenses', ['downloadId' => $this->getCanonicalId()]));
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_DOWNLOADS);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_DOWNLOADS);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_DOWNLOADS);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_DOWNLOADS);
    }
}
