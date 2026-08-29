<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\digits\models\DownloadFile;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\queue\jobs\NotifyVersion;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Editing and releasing versions.
 *
 * Saving and publishing are separate buttons, because they are separate decisions: a build can be
 * uploaded, its notes written and its files checked days before anybody is meant to have it.
 * Publishing is the moment that stamps the release date — which every "your licence covers updates
 * until…" decision is then measured against — and the moment the notices go out.
 */
class VersionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_DOWNLOADS);

        return true;
    }

    public function actionEdit(int $downloadId, ?int $versionId = null, ?Version $version = null): Response
    {
        $plugin = Plugin::getInstance();
        $download = $plugin->downloads->getDownloadById($downloadId);

        if ($download === null) {
            throw new NotFoundHttpException('Download not found.');
        }

        if ($version === null) {
            if ($versionId !== null) {
                $version = $plugin->versions->getVersionById($versionId);

                if ($version === null || (int)$version->downloadId !== $downloadId) {
                    throw new NotFoundHttpException('Version not found.');
                }
            } else {
                $version = new Version(['downloadId' => $downloadId]);
            }
        }

        return $this->renderTemplate('digits/versions/_edit', [
            'download' => $download,
            'version' => $version,
            'isNew' => $version->id === null,
            'plugin' => $plugin,
            'noticeCount' => $version->id !== null ? $plugin->notifications->noticeCount((int)$version->id) : 0,
            'canNotify' => Edition::allowsUpdateNotices($plugin->isPro()),
            'title' => $version->id !== null
                ? Craft::t('digits', 'Version {version}', ['version' => $version->version])
                : Craft::t('digits', 'New version'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $downloadId = (int)$this->request->getRequiredBodyParam('downloadId');
        $versionId = $this->request->getBodyParam('versionId');

        $version = $versionId
            ? $plugin->versions->getVersionById((int)$versionId)
            : new Version(['downloadId' => $downloadId]);

        if ($version === null) {
            throw new NotFoundHttpException('Version not found.');
        }

        $version->version = trim((string)$this->request->getBodyParam('version', ''));
        $version->releaseNotes = $this->request->getBodyParam('releaseNotes') ?: null;
        $version->setFiles($this->filesFromRequest());

        $publish = (bool)$this->request->getBodyParam('publish');
        $notify = (bool)$this->request->getBodyParam('notify');

        if ($publish) {
            $version->status = Version::STATUS_LIVE;
            $version->dateReleased ??= DateTimeHelper::toDateTime($this->request->getBodyParam('dateReleased')) ?: new \DateTime();
        }

        $saved = $publish
            ? $plugin->versions->publish($version, (bool)$this->request->getBodyParam('makeCurrent', true))
            : $plugin->versions->saveVersion($version);

        if (!$saved) {
            $this->setFailFlash(Craft::t('digits', 'Couldn’t save the version.'));
            Craft::$app->getUrlManager()->setRouteParams(['version' => $version, 'downloadId' => $downloadId]);

            return null;
        }

        if ($publish && $notify && Edition::allowsUpdateNotices($plugin->isPro())) {
            Queue::push(new NotifyVersion([
                'versionId' => (int)$version->id,
                // Stamped now, so a licence issued *after* the release is not emailed about a
                // version it was born holding.
                'issuedBefore' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]));

            $this->setSuccessFlash(Craft::t('digits', 'Version released. Notices are being sent.'));
        } else {
            $this->setSuccessFlash($publish
                ? Craft::t('digits', 'Version released.')
                : Craft::t('digits', 'Version saved.'));
        }

        return $this->redirect(UrlHelper::cpUrl('digits/downloads/' . $downloadId));
    }

    public function actionMakeCurrent(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $version = $plugin->versions->getVersionById((int)$this->request->getRequiredBodyParam('versionId'));

        if ($version === null) {
            throw new NotFoundHttpException('Version not found.');
        }

        $plugin->versions->makeCurrent($version);

        $this->setSuccessFlash(Craft::t('digits', 'Version {version} is now the current one.', ['version' => $version->version]));

        return $this->redirect(UrlHelper::cpUrl('digits/downloads/' . $version->downloadId));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $version = $plugin->versions->getVersionById((int)$this->request->getRequiredBodyParam('versionId'));

        if ($version === null) {
            throw new NotFoundHttpException('Version not found.');
        }

        $downloadId = (int)$version->downloadId;
        $plugin->versions->deleteVersionById((int)$version->id);

        return $this->asSuccess(
            Craft::t('digits', 'Version deleted.'),
            [],
            UrlHelper::cpUrl('digits/downloads/' . $downloadId),
        );
    }

    /**
     * The files table, as posted.
     *
     * Craft's editable table posts a row per file with an asset field's value as an array of ids;
     * an empty row at the end is the table's "add" placeholder and is dropped rather than saved as
     * a file with no name.
     *
     * @return DownloadFile[]
     */
    private function filesFromRequest(): array
    {
        $rows = $this->request->getBodyParam('files') ?: [];
        $files = [];

        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            $assetIds = array_filter((array)($row['assetId'] ?? []));
            $assetId = $assetIds !== [] ? (int)reset($assetIds) : null;

            if ($name === '' && $url === '' && $assetId === null) {
                continue;
            }

            $files[] = new DownloadFile([
                'id' => !empty($row['id']) ? (int)$row['id'] : null,
                'name' => $name,
                'assetId' => $assetId,
                'url' => $url !== '' ? $url : null,
                'filename' => trim((string)($row['filename'] ?? '')) ?: null,
                'size' => !empty($row['size']) ? (int)$row['size'] : null,
            ]);
        }

        return $files;
    }
}
