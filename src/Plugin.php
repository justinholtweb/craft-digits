<?php

declare(strict_types=1);

namespace justinholtweb\digits;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\fields\DownloadsField;
use justinholtweb\digits\models\Settings;
use justinholtweb\digits\queue\jobs\PruneLog;
use justinholtweb\digits\services\Access;
use justinholtweb\digits\services\Activations;
use justinholtweb\digits\services\Delivery;
use justinholtweb\digits\services\Downloads;
use justinholtweb\digits\services\Invoices;
use justinholtweb\digits\services\Keys;
use justinholtweb\digits\services\Licenses;
use justinholtweb\digits\services\Links;
use justinholtweb\digits\services\Log;
use justinholtweb\digits\services\Notifications;
use justinholtweb\digits\services\Orders;
use justinholtweb\digits\services\Vat;
use justinholtweb\digits\services\Versions;
use justinholtweb\digits\twig\DigitsVariable;
use yii\base\Event;

/**
 * Digits — gated digital downloads and software licensing for Craft CMS.
 *
 * Craft's own `craftcms/digital-products` gives Commerce a digital purchasable and stops there.
 * What a shop selling files actually needs afterwards is the part that is missing: links that
 * expire, downloads that are counted, files that have versions, keys that software can check, an
 * account page the customer can find on their own, a refund that actually takes the files back, and
 * a VAT invoice that says the right thing. That is this plugin.
 *
 * ## Commerce is optional
 *
 * Everything except buying works without it. A download gated to a user group, a manual licence, a
 * link that expires in an hour and a counted delivery are all Craft-only features, and the entire
 * Commerce integration is one service ({@see Orders}) behind one guard ({@see commerceIsReady()}).
 * That is not politeness towards non-Commerce sites — it is what keeps the licence model from
 * quietly becoming "a row that belongs to an order", which is the shape that makes refunds,
 * renewals and manual grants each need their own special case.
 *
 * @property-read Downloads $downloads
 * @property-read Versions $versions
 * @property-read Licenses $licenses
 * @property-read Keys $keys
 * @property-read Activations $activations
 * @property-read Access $access
 * @property-read Links $links
 * @property-read Delivery $delivery
 * @property-read Orders $orders
 * @property-read Invoices $invoices
 * @property-read Vat $vat
 * @property-read Notifications $notifications
 * @property-read Log $log
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'digits';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW_DOWNLOADS = 'digits:viewDownloads';
    public const PERMISSION_MANAGE_DOWNLOADS = 'digits:manageDownloads';
    public const PERMISSION_VIEW_LICENSES = 'digits:viewLicenses';
    public const PERMISSION_MANAGE_LICENSES = 'digits:manageLicenses';
    public const PERMISSION_DELETE_LICENSES = 'digits:deleteLicenses';
    public const PERMISSION_VIEW_LOG = 'digits:viewLog';
    public const PERMISSION_MANAGE_INVOICES = 'digits:manageInvoices';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'digits';

    /** Where the licence element's field layout lives in project config. */
    public const CONFIG_LICENSE_FIELD_LAYOUT_KEY = 'digits.licenses.fieldLayouts';

    /** Where the download element's field layout lives in project config. */
    public const CONFIG_DOWNLOAD_FIELD_LAYOUT_KEY = 'digits.downloads.fieldLayouts';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'downloads' => Downloads::class,
                'versions' => Versions::class,
                'licenses' => Licenses::class,
                'keys' => Keys::class,
                'activations' => Activations::class,
                'access' => Access::class,
                'links' => Links::class,
                'delivery' => Delivery::class,
                'orders' => Orders::class,
                'invoices' => Invoices::class,
                'vat' => Vat::class,
                'notifications' => Notifications::class,
                'log' => Log::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerProjectConfig();
        $this->registerSystemMessages();
        $this->registerTwig();
        $this->registerUserEvents();
        $this->registerGarbageCollection();

        // The plugin can be installed while Commerce is absent, disabled, or mid-upgrade, and
        // everything below reaches for classes that would not be there.
        if (self::commerceIsReady()) {
            $this->registerCommerce();
        }
    }

    /** Whether Commerce is present and switched on. */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /** Whether this install is licensed for the Pro feature set. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Digits' settings are several screens, not one pane.
     *
     * Never a redirect to `settings/plugins/digits` — that *is* the URL Craft renders
     * `settingsHtml()` at, so overriding it that way is an infinite redirect.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('digits/settings'));
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('digits', 'Digits');

        $user = Craft::$app->getUser();
        $subnav = [];

        if ($user->checkPermission(self::PERMISSION_VIEW_DOWNLOADS)) {
            $subnav['downloads'] = [
                'label' => Craft::t('digits', 'Downloads'),
                'url' => 'digits/downloads',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_VIEW_LICENSES)) {
            $subnav['licenses'] = [
                'label' => Craft::t('digits', 'Licences'),
                'url' => 'digits/licenses',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_VIEW_LOG)) {
            $subnav['log'] = [
                'label' => Craft::t('digits', 'Activity'),
                'url' => 'digits/log',
            ];
        }

        if ($this->isPro()
            && $this->getSettings()->enableInvoicing
            && $user->checkPermission(self::PERMISSION_MANAGE_INVOICES)) {
            $subnav['invoices'] = [
                'label' => Craft::t('digits', 'Invoices'),
                'url' => 'digits/invoices',
            ];
        }

        if ($user->getIsAdmin()) {
            $subnav['settings'] = [
                'label' => Craft::t('digits', 'Settings'),
                'url' => 'digits/settings',
            ];
        }

        if ($subnav === []) {
            return null;
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    // Wiring
    // -------------------------------------------------------------------------

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = Download::class;
            $event->types[] = License::class;
        });
    }

    private function registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = DownloadsField::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'digits' => 'digits/downloads/index',
                'digits/downloads' => 'digits/downloads/index',
                'digits/downloads/new' => 'digits/downloads/edit',
                'digits/downloads/<downloadId:\d+>' => 'digits/downloads/edit',
                'digits/downloads/<downloadId:\d+>/versions/new' => 'digits/versions/edit',
                'digits/downloads/<downloadId:\d+>/versions/<versionId:\d+>' => 'digits/versions/edit',
                'digits/licenses' => 'digits/licenses/index',
                'digits/licenses/new' => 'digits/licenses/edit',
                'digits/licenses/<licenseId:\d+>' => 'digits/licenses/edit',
                'digits/log' => 'digits/log/index',
                'digits/invoices' => 'digits/invoices/index',
                'digits/invoices/report' => 'digits/invoices/report',
                'digits/invoices/<invoiceId:\d+>' => 'digits/invoices/detail',
                'digits/settings' => 'digits/settings/index',
                'digits/settings/downloads' => 'digits/settings/downloads',
                'digits/settings/licenses' => 'digits/settings/licenses',
                'digits/settings/portal' => 'digits/settings/portal',
                'digits/settings/invoicing' => 'digits/settings/invoicing',
                'digits/settings/vat' => 'digits/settings/vat',
                'digits/settings/fields' => 'digits/settings/fields',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            // Documented, stable URLs as well as the action routes, so a customer's software can
            // be built against a URL that does not contain the word "actions" — and so a link in an
            // email from 2026 still reads like a link in 2030.
            $event->rules['digits/download/<token:[A-Za-z0-9\-_]+>'] = 'digits/file/download';
            $event->rules['digits/api/activate'] = 'digits/api/activate';
            $event->rules['digits/api/deactivate'] = 'digits/api/deactivate';
            $event->rules['digits/api/check'] = 'digits/api/check';
            $event->rules['digits/api/versions'] = 'digits/api/versions';

            $path = trim($this->getSettings()->portalPath, '/');

            if ($path !== '') {
                $event->rules[$path] = 'digits/portal/index';
                $event->rules[$path . '/license/<licenseId:\d+>'] = 'digits/portal/license';
                $event->rules[$path . '/invoice/<invoiceId:\d+>'] = 'digits/portal/invoice';
            }
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('digits', 'Digits'),
                'permissions' => [
                    self::PERMISSION_VIEW_DOWNLOADS => [
                        'label' => Craft::t('digits', 'View downloads'),
                        'nested' => [
                            self::PERMISSION_MANAGE_DOWNLOADS => [
                                'label' => Craft::t('digits', 'Create and edit downloads and versions'),
                            ],
                        ],
                    ],
                    // Deliberately not nested under downloads. A licence carries a named customer's
                    // email address and purchase history; a download does not. Plenty of teams want
                    // somebody uploading new builds who has no business reading the customer list.
                    self::PERMISSION_VIEW_LICENSES => [
                        'label' => Craft::t('digits', 'View licences'),
                        'nested' => [
                            self::PERMISSION_MANAGE_LICENSES => [
                                'label' => Craft::t('digits', 'Issue, revoke and edit licences'),
                            ],
                            self::PERMISSION_DELETE_LICENSES => [
                                'label' => Craft::t('digits', 'Delete licences'),
                            ],
                        ],
                    ],
                    self::PERMISSION_VIEW_LOG => [
                        'label' => Craft::t('digits', 'View the download activity log'),
                    ],
                    self::PERMISSION_MANAGE_INVOICES => [
                        'label' => Craft::t('digits', 'View and issue VAT invoices'),
                    ],
                ],
            ];
        });
    }

    private function registerProjectConfig(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(self::CONFIG_DOWNLOAD_FIELD_LAYOUT_KEY, [$this, 'handleChangedDownloadFieldLayout'])
            ->onUpdate(self::CONFIG_DOWNLOAD_FIELD_LAYOUT_KEY, [$this, 'handleChangedDownloadFieldLayout'])
            ->onRemove(self::CONFIG_DOWNLOAD_FIELD_LAYOUT_KEY, [$this, 'handleChangedDownloadFieldLayout'])
            ->onAdd(self::CONFIG_LICENSE_FIELD_LAYOUT_KEY, [$this, 'handleChangedLicenseFieldLayout'])
            ->onUpdate(self::CONFIG_LICENSE_FIELD_LAYOUT_KEY, [$this, 'handleChangedLicenseFieldLayout'])
            ->onRemove(self::CONFIG_LICENSE_FIELD_LAYOUT_KEY, [$this, 'handleChangedLicenseFieldLayout']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, static function(RebuildConfigEvent $event) {
            $fields = Craft::$app->getFields();

            foreach ([
                Download::class => 'downloads',
                License::class => 'licenses',
            ] as $class => $key) {
                $layout = $fields->getLayoutByType($class);

                if ($layout->uid !== null) {
                    $event->config['digits'][$key]['fieldLayouts'] = [$layout->uid => $layout->getConfig()];
                }
            }
        });
    }

    public function saveFieldLayout(\craft\models\FieldLayout $layout, string $class, string $configKey): bool
    {
        $layout->type = $class;
        $layout->uid ??= \craft\helpers\StringHelper::UUID();

        Craft::$app->getProjectConfig()->set(
            $configKey,
            [$layout->uid => $layout->getConfig()],
            sprintf('Save Digits’ %s field layout', $class),
        );

        return true;
    }

    public function handleChangedDownloadFieldLayout(\craft\events\ConfigEvent $event): void
    {
        $this->applyFieldLayout($event, Download::class);
    }

    public function handleChangedLicenseFieldLayout(\craft\events\ConfigEvent $event): void
    {
        $this->applyFieldLayout($event, License::class);
    }

    private function applyFieldLayout(\craft\events\ConfigEvent $event, string $class): void
    {
        $data = $event->newValue;
        $fields = Craft::$app->getFields();

        if (empty($data) || empty($config = reset($data))) {
            $fields->deleteLayoutsByType($class);

            return;
        }

        \craft\helpers\ProjectConfig::ensureAllFieldsProcessed();

        $layout = \craft\models\FieldLayout::createFromConfig($config);
        $layout->id = $fields->getLayoutByType($class)->id;
        $layout->type = $class;
        $layout->uid = key($data);
        $fields->saveLayout($layout, false);

        Craft::$app->getElements()->invalidateCachesForElementType($class);
    }

    private function registerSystemMessages(): void
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, static function(RegisterEmailMessagesEvent $event) {
            foreach (Notifications::systemMessages() as $message) {
                $event->messages[] = $message;
            }
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            $event->sender->set('digits', DigitsVariable::class);
        });
    }

    /**
     * Hand a new account the licences already bearing its email address.
     *
     * Without this, a guest checkout followed by "create an account" produces an empty portal,
     * which reads to the customer as having lost what they paid for.
     */
    private function registerUserEvents(): void
    {
        Event::on(User::class, User::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var User $user */
            $user = $event->sender;

            if ($user->getIsCredentialed() && !$user->propagating) {
                $this->licenses->claimForUser($user);
            }
        });
    }

    /**
     * Housekeeping, hooked to Craft's own garbage collection.
     *
     * Pruning tokens and stamping lapsed licences are both cheap and idempotent, so they run
     * inline. The log sweep is queued — it can delete millions of rows, and nobody's page load
     * should pay for that.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->links->prune();
            $this->licenses->expireLapsed();

            if ($this->getSettings()->logRetentionDays > 0) {
                Queue::push(new PruneLog());
            }
        });
    }

    /**
     * The Commerce integration, in one place.
     *
     * Four events, and nothing else in the plugin knows Commerce exists.
     */
    private function registerCommerce(): void
    {
        Event::on(
            \craft\commerce\elements\Order::class,
            \craft\commerce\elements\Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                /** @var \craft\commerce\elements\Order $order */
                $order = $event->sender;

                $this->orders->handleOrderComplete($order);
                $this->orders->maybeInvoice($order);
            },
        );

        Event::on(
            \craft\commerce\elements\Order::class,
            \craft\commerce\elements\Order::EVENT_AFTER_ORDER_PAID,
            function(Event $event) {
                $this->orders->handleOrderPaid($event->sender);
            },
        );

        Event::on(
            \craft\commerce\services\Transactions::class,
            \craft\commerce\services\Transactions::EVENT_AFTER_SAVE_TRANSACTION,
            function(\craft\commerce\events\TransactionEvent $event) {
                $this->orders->handleTransaction($event->transaction);
            },
        );

        Event::on(
            \craft\commerce\services\OrderHistories::class,
            \craft\commerce\services\OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            function(\craft\commerce\events\OrderStatusEvent $event) {
                $this->orders->handleStatusChange($event->order);
            },
        );

        // The licences an order produced, on the order's own edit screen. Where support actually
        // looks when a customer says the download stopped working.
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof \craft\commerce\elements\Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW_LICENSES)) {
                return null;
            }

            $licenses = $this->licenses->getLicensesForOrder((int)$order->id);
            $invoices = $this->getSettings()->enableInvoicing && $this->isPro()
                ? $this->invoices->getInvoicesForOrder((int)$order->id)
                : [];

            if ($licenses === [] && $invoices === []) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('digits/_order-panel', [
                'order' => $order,
                'licenses' => $licenses,
                'invoices' => $invoices,
            ]);
        });
    }
}
