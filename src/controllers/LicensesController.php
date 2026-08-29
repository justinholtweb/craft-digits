<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The licences screens.
 *
 * This is where support lives. Everything on the edit screen is arranged around one job: somebody
 * has emailed to say a download is not working, and the person answering has to find out why in
 * under a minute — which licence, what state it is in, how many downloads are left, which machines
 * it is on, and what the log says was refused and for what reason.
 */
class LicensesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_LICENSES);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('digits/licenses/_index', [
            'canCreate' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_LICENSES),
        ]);
    }

    public function actionEdit(?int $licenseId = null, ?License $license = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($license === null) {
            if ($licenseId !== null) {
                $license = $plugin->licenses->getLicenseById($licenseId);

                if ($license === null) {
                    throw new NotFoundHttpException('Licence not found.');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE_LICENSES);

                $license = new License();
                $license->issuedDate = new \DateTime();
            }
        }

        return $this->renderTemplate('digits/licenses/_edit', [
            'license' => $license,
            'isNew' => $license->id === null,
            'plugin' => $plugin,
            'downloads' => $plugin->downloads->getAllDownloads(),
            'activations' => $license->id !== null ? $license->getActivations() : [],
            'showActivations' => Edition::allowsActivations($plugin->isPro()),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_LICENSES),
            'recentLog' => $license->id !== null
                ? $plugin->log->find(['licenseId' => $license->id], 25)
                : [],
            'title' => $license->id !== null ? $license->getUiLabel() : Craft::t('digits', 'New licence'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_LICENSES);

        $plugin = Plugin::getInstance();
        $licenseId = $this->request->getBodyParam('licenseId');

        $license = $licenseId
            ? $plugin->licenses->getLicenseById((int)$licenseId)
            : new License(['source' => License::SOURCE_MANUAL]);

        if ($license === null) {
            throw new NotFoundHttpException('Licence not found.');
        }

        $license->email = trim((string)$this->request->getBodyParam('email', $license->email));
        $license->customerName = $this->request->getBodyParam('customerName') ?: null;
        $license->notes = $this->request->getBodyParam('notes') ?: null;
        $license->licenseStatus = $this->request->getBodyParam('licenseStatus', $license->licenseStatus);
        $license->setDownloadIds($this->request->getBodyParam('downloadIds', []) ?: []);

        $ownerIds = array_filter((array)$this->request->getBodyParam('ownerId', []));
        $license->ownerId = $ownerIds !== [] ? (int)reset($ownerIds) : null;

        // An empty expiry box is "never", which is a real answer and not a missing one.
        $expiry = $this->request->getBodyParam('expiryDate');
        $license->expiryDate = $expiry ? DateTimeHelper::toDateTime($expiry) ?: null : null;

        foreach (['downloadLimit', 'activationLimit'] as $attribute) {
            $value = $this->request->getBodyParam($attribute);
            $license->$attribute = ($value === null || $value === '') ? null : max(0, (int)$value);
        }

        if (Edition::allowsLicenseKeys($plugin->isPro())) {
            $key = trim((string)$this->request->getBodyParam('licenseKey', ''));

            if ($key !== '') {
                $license->licenseKey = $key;
            }
        }

        $license->setFieldValuesFromRequest('fields');

        if (!Craft::$app->getElements()->saveElement($license)) {
            $this->setFailFlash(Craft::t('digits', 'Couldn’t save the licence.'));
            Craft::$app->getUrlManager()->setRouteParams(['license' => $license]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('digits', 'Licence saved.'));

        return $this->redirectToPostedUrl($license);
    }

    public function actionRevoke(): Response
    {
        $license = $this->requireLicenseForAction();

        Plugin::getInstance()->licenses->revoke(
            $license,
            $this->request->getBodyParam('reason') ?: Craft::t('digits', 'Revoked in the control panel'),
        );

        // `asSuccess()` rather than a flash and a redirect: these buttons post through
        // `Craft.sendActionRequest`, which asks for JSON, and a 302 answered to an XHR is how a
        // secondary action appears to do nothing at all.
        return $this->asSuccess(
            Craft::t('digits', 'Licence revoked. Its outstanding download links no longer work.'),
            [],
            UrlHelper::cpUrl('digits/licenses/' . $license->id),
        );
    }

    public function actionRestore(): Response
    {
        $license = $this->requireLicenseForAction();

        Plugin::getInstance()->licenses->restore($license);

        return $this->asSuccess(
            Craft::t('digits', 'Licence restored.'),
            [],
            UrlHelper::cpUrl('digits/licenses/' . $license->id),
        );
    }

    public function actionResetCount(): Response
    {
        $license = $this->requireLicenseForAction();

        Plugin::getInstance()->licenses->resetDownloadCount($license);

        return $this->asSuccess(
            Craft::t('digits', 'Download count reset.'),
            [],
            UrlHelper::cpUrl('digits/licenses/' . $license->id),
        );
    }

    public function actionExtend(): Response
    {
        $license = $this->requireLicenseForAction();
        $days = max(1, (int)$this->request->getBodyParam('days', 365));

        Plugin::getInstance()->licenses->extend($license, $days);

        return $this->asSuccess(
            Craft::t('digits', 'Licence extended by {days} days.', ['days' => $days]),
            [],
            UrlHelper::cpUrl('digits/licenses/' . $license->id),
        );
    }

    public function actionDeactivate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_LICENSES);

        $activationId = (int)$this->request->getRequiredBodyParam('activationId');
        $licenseId = (int)$this->request->getRequiredBodyParam('licenseId');

        Plugin::getInstance()->activations->deactivateById($activationId);

        return $this->asSuccess(
            Craft::t('digits', 'Activation removed.'),
            [],
            UrlHelper::cpUrl('digits/licenses/' . $licenseId),
        );
    }

    /** Send the customer their licence and links again — the single most common support action. */
    public function actionResend(): Response
    {
        $license = $this->requireLicenseForAction();

        Plugin::getInstance()->notifications->sendLicenseIssued($license->getOrder(), [$license]);

        return $this->asSuccess(
            Craft::t('digits', 'Email sent to {email}.', ['email' => $license->email]),
            [],
            UrlHelper::cpUrl('digits/licenses/' . $license->id),
        );
    }

    private function requireLicenseForAction(): License
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_LICENSES);

        $license = Plugin::getInstance()->licenses->getLicenseById(
            (int)$this->request->getRequiredBodyParam('licenseId'),
        );

        if ($license === null) {
            throw new NotFoundHttpException('Licence not found.');
        }

        return $license;
    }
}
