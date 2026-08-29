<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\services\Log;
use yii\web\Response;

/**
 * The activity screen.
 *
 * Refusals are shown next to deliveries and not on a separate tab, because the question this screen
 * exists to answer is "what happened to this customer", and the answer is usually a refusal sitting
 * between two successes.
 */
class LogController extends Controller
{
    private const PER_PAGE = 100;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_LOG);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $page = max(1, (int)$this->request->getParam('page', 1));

        $criteria = array_filter([
            'event' => $this->request->getParam('event') ?: null,
            'downloadId' => $this->request->getParam('downloadId') ?: null,
            'licenseId' => $this->request->getParam('licenseId') ?: null,
            'email' => $this->request->getParam('email') ?: null,
        ]);

        $total = $plugin->log->count($criteria);

        return $this->renderTemplate('digits/log/_index', [
            'rows' => $plugin->log->find($criteria, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pageCount' => (int)ceil($total / self::PER_PAGE),
            'criteria' => $criteria,
            'events' => [
                '' => Craft::t('digits', 'All activity'),
                Log::EVENT_DOWNLOAD => Craft::t('digits', 'Downloads'),
                Log::EVENT_DENIED => Craft::t('digits', 'Refusals'),
                Log::EVENT_ISSUE => Craft::t('digits', 'Licences issued'),
                Log::EVENT_REVOKE => Craft::t('digits', 'Licences revoked'),
                Log::EVENT_ACTIVATE => Craft::t('digits', 'Activations'),
                Log::EVENT_DEACTIVATE => Craft::t('digits', 'Deactivations'),
                Log::EVENT_NOTICE => Craft::t('digits', 'Release notices'),
            ],
            'downloads' => $plugin->downloads->getAllDownloads(),
        ]);
    }
}
