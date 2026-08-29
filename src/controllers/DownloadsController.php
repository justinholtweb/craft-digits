<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The downloads screens.
 *
 * A bespoke edit screen rather than Craft's generic element editor, because the half of a download
 * that matters is a set of *rules* — who may have it, how many times, for how long, how many
 * machines — and beneath them a release history, and neither is a form control Craft has a generic
 * version of.
 */
class DownloadsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_DOWNLOADS);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('digits/downloads/_index', [
            'canCreate' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_DOWNLOADS),
        ]);
    }

    public function actionEdit(?int $downloadId = null, ?Download $download = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($download === null) {
            if ($downloadId !== null) {
                $download = $plugin->downloads->getDownloadById($downloadId);

                if ($download === null) {
                    throw new NotFoundHttpException('Download not found.');
                }
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE_DOWNLOADS);

                $download = new Download();
                $download->enabled = true;
            }
        }

        return $this->renderTemplate('digits/downloads/_edit', [
            'download' => $download,
            'isNew' => $download->id === null,
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'versions' => $download->id !== null ? $download->getVersions(false) : [],
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_DOWNLOADS),
            'canAddVersion' => $download->id === null || $plugin->versions->canAddVersion((int)$download->id),
            'dailyCounts' => $dailyCounts = ($download->id !== null ? $plugin->log->dailyCounts((int)$download->id) : []),
            // Summed here rather than in the template: Twig has no `sum` filter, and a `reduce`
            // in a sidebar is a worse way to say "add up an array of integers".
            'downloadTotal' => array_sum($dailyCounts),
            'title' => $download->id !== null ? $download->getUiLabel() : Craft::t('digits', 'New download'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_DOWNLOADS);

        $plugin = Plugin::getInstance();
        $downloadId = $this->request->getBodyParam('downloadId');

        if ($downloadId) {
            $download = $plugin->downloads->getDownloadById((int)$downloadId);

            if ($download === null) {
                throw new NotFoundHttpException('Download not found.');
            }
        } else {
            $download = new Download();
        }

        $download->title = $this->request->getBodyParam('title', $download->title);
        $download->slug = $this->request->getBodyParam('slug', $download->slug);
        $download->enabled = (bool)$this->request->getBodyParam('enabled', $download->enabled);
        $download->sku = $this->trimmed('sku') ?: null;
        $download->accessType = $this->request->getBodyParam('accessType', $download->accessType);
        $download->setGroupIds($this->request->getBodyParam('groupIds', []) ?: []);
        $download->issuesLicense = (bool)$this->request->getBodyParam('issuesLicense', false);
        $download->gateUpdatesOnExpiry = (bool)$this->request->getBodyParam('gateUpdatesOnExpiry', false);
        $download->notifyOnRelease = (bool)$this->request->getBodyParam('notifyOnRelease', false);

        // A blank box means "inherit the site default", and an empty string is not zero: `0` is a
        // deliberate "unlimited" and has to survive the round trip as a nought rather than as null.
        foreach (['downloadLimit', 'accessDays', 'linkTtl', 'activationLimit'] as $attribute) {
            $value = $this->request->getBodyParam($attribute);
            $download->$attribute = ($value === null || $value === '') ? null : max(0, (int)$value);
        }

        $download->setFieldValuesFromRequest('fields');

        if (!Craft::$app->getElements()->saveElement($download)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asModelFailure($download, Craft::t('digits', 'Couldn’t save the download.'), 'download');
            }

            $this->setFailFlash(Craft::t('digits', 'Couldn’t save the download.'));
            Craft::$app->getUrlManager()->setRouteParams(['download' => $download]);

            return null;
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asModelSuccess($download, Craft::t('digits', 'Download saved.'), 'download');
        }

        $this->setSuccessFlash(Craft::t('digits', 'Download saved.'));

        return $this->redirectToPostedUrl($download);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_DOWNLOADS);

        $download = Plugin::getInstance()->downloads->getDownloadById(
            (int)$this->request->getRequiredBodyParam('downloadId'),
        );

        if ($download === null) {
            throw new NotFoundHttpException('Download not found.');
        }

        Craft::$app->getElements()->deleteElement($download);

        $this->setSuccessFlash(Craft::t('digits', 'Download deleted.'));

        return $this->redirect(UrlHelper::cpUrl('digits/downloads'));
    }

    private function trimmed(string $param): string
    {
        return trim((string)$this->request->getBodyParam($param, ''));
    }
}
