<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\VatRate;
use justinholtweb\digits\Plugin;
use yii\web\Response;

/**
 * The settings screens.
 *
 * Several screens rather than one long pane, because Digits' settings are genuinely several
 * subjects — how files are delivered, how keys are shaped, what the account page is called, and a
 * whole tax apparatus — and putting them on one page is how nobody ever finds the delivery method.
 *
 * ## Saving is always the whole model
 *
 * `savePluginSettings()` **replaces** what is in project config rather than merging into it. Posting
 * one screen's fields and saving those would therefore wipe the other four screens' settings, and
 * would do it silently. So every save starts from the live settings model, applies what was posted,
 * and writes the lot back.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('digits/settings/downloads'));
    }

    public function actionDownloads(): Response
    {
        return $this->screen('downloads', [
            'hasDownloadsField' => Plugin::getInstance()->downloads->hasAnyDownloadsField(),
        ]);
    }

    public function actionLicenses(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->screen('licenses', [
            'sampleKey' => $plugin->keys->format(),
            'orderStatuses' => $this->orderStatusOptions(),
            'allowsKeys' => Edition::allowsLicenseKeys($plugin->isPro()),
        ]);
    }

    public function actionPortal(): Response
    {
        return $this->screen('portal', [
            'allowsPortal' => Edition::allowsPortal(Plugin::getInstance()->isPro()),
        ]);
    }

    public function actionInvoicing(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->screen('invoicing', [
            'allowsInvoicing' => Edition::allowsInvoicing($plugin->isPro()),
            'nextNumber' => $plugin->invoices->previewNumber($plugin->getSettings()->invoiceSeries),
            'countries' => $this->countryOptions(),
        ]);
    }

    public function actionVat(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->screen('vat', [
            'rates' => $plugin->vat->getAllRates(),
            'seedDate' => $plugin->vat->getSeedDate(),
            'countries' => $this->countryOptions(),
        ]);
    }

    /** The field layouts for the two element types, on one screen. */
    public function actionFields(): Response
    {
        $fields = Craft::$app->getFields();

        return $this->screen('fields', [
            'downloadLayout' => $fields->getLayoutByType(Download::class),
            'licenseLayout' => $fields->getLayoutByType(License::class),
            'which' => $this->request->getParam('which') === 'license' ? 'license' : 'download',
        ]);
    }

    /**
     * Save one screen's worth of settings.
     *
     * `setAttributes(..., false)` — validation off at assignment — because every value arriving
     * from an HTTP request is a string, and a typed `public int $linkTtl` refuses `'1440'` on the
     * way in. The model's own rules then run on the typed values, which is where the checking
     * belongs.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $posted = $this->request->getBodyParam('settings', []);

        // Checkboxes that are off post nothing at all, so a screen has to say which switches it is
        // responsible for or turning one off would never save.
        foreach ((array)$this->request->getBodyParam('booleans', []) as $name) {
            $posted[$name] = (bool)($posted[$name] ?? false);
        }

        foreach (['linkTtl', 'downloadLimit', 'accessDays', 'activationLimit', 'logRetentionDays',
            'portalTokenTtl', 'invoiceNumberPadding', 'invoiceStartNumber', 'vatIdCacheDays', 'viesTimeout'] as $name) {
            if (isset($posted[$name]) && $posted[$name] !== '') {
                $posted[$name] = (int)$posted[$name];
            }
        }

        $settings->setAttributes($posted, false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('digits', 'Couldn’t save settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('digits', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionSaveFieldLayout(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $which = $this->request->getRequiredBodyParam('which');

        [$class, $configKey] = $which === 'license'
            ? [License::class, Plugin::CONFIG_LICENSE_FIELD_LAYOUT_KEY]
            : [Download::class, Plugin::CONFIG_DOWNLOAD_FIELD_LAYOUT_KEY];

        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        $layout->type = $class;

        $existing = Craft::$app->getFields()->getLayoutByType($class);
        $layout->id = $existing->id;
        $layout->uid = $existing->uid;

        $plugin->saveFieldLayout($layout, $class, $configKey);

        $this->setSuccessFlash(Craft::t('digits', 'Fields saved.'));

        return $this->redirectToPostedUrl();
    }

    // VAT rates
    // -------------------------------------------------------------------------

    public function actionSaveRate(): ?Response
    {
        $this->requirePostRequest();

        $rate = new VatRate([
            'id' => $this->request->getBodyParam('id') ?: null,
            'countryCode' => strtoupper(trim((string)$this->request->getBodyParam('countryCode', ''))),
            'name' => $this->request->getBodyParam('name') ?: null,
            'rate' => (float)$this->request->getBodyParam('rate', 0),
            'kind' => $this->request->getBodyParam('kind', VatRate::KIND_STANDARD),
            'effectiveFrom' => DateTimeHelper::toDateTime($this->request->getBodyParam('effectiveFrom')) ?: null,
            'effectiveTo' => DateTimeHelper::toDateTime($this->request->getBodyParam('effectiveTo')) ?: null,
        ]);

        if (!Plugin::getInstance()->vat->saveRate($rate)) {
            $this->setFailFlash(Craft::t('digits', 'Couldn’t save the rate.'));

            return $this->redirect(UrlHelper::cpUrl('digits/settings/vat'));
        }

        $this->setSuccessFlash(Craft::t('digits', 'Rate saved.'));

        return $this->redirect(UrlHelper::cpUrl('digits/settings/vat'));
    }

    public function actionDeleteRate(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->vat->deleteRateById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(
            Craft::t('digits', 'Rate deleted.'),
            [],
            UrlHelper::cpUrl('digits/settings/vat'),
        );
    }

    /** Check a VAT number from the control panel — the support answer to "is this customer B2B". */
    public function actionCheckVatId(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $check = Plugin::getInstance()->vat->validateVatId(
            (string)$this->request->getRequiredBodyParam('vatId'),
            true,
        );

        return $this->asJson($check->toArray() + ['sourceLabel' => $check->getSourceLabel()]);
    }

    private function screen(string $key, array $variables = []): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('digits/settings/_' . $key, array_merge([
            'plugin' => $plugin,
            'settings' => $variables['settings'] ?? $plugin->getSettings(),
            'selectedItem' => $key,
            'isPro' => $plugin->isPro(),
        ], $variables));
    }

    private function orderStatusOptions(): array
    {
        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $options = [];

        foreach (\craft\commerce\Plugin::getInstance()->getOrderStatuses()->getAllOrderStatuses() as $status) {
            $options[] = ['label' => $status->name, 'value' => $status->handle];
        }

        return $options;
    }

    private function countryOptions(): array
    {
        $options = [['label' => Craft::t('digits', 'Not set'), 'value' => '']];

        foreach (Craft::$app->getAddresses()->getCountryRepository()->getList() as $code => $name) {
            $options[] = ['label' => $name, 'value' => $code];
        }

        return $options;
    }
}
