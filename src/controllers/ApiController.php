<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\digits\models\ActivationResult;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\Plugin;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * The activation API — the endpoint a customer's software talks to.
 *
 * Four calls, all JSON, all answering in the same envelope: `success`, a machine-readable `code`,
 * and a sentence the vendor may show a human. That shape is the point. A plugin, a desktop app or a
 * theme checking its licence has to be able to tell "the key is wrong" from "you are out of seats"
 * from "we could not reach the server", because those are three different dialogues and only one
 * of them should ever nag the customer.
 *
 * ## Not CSRF-protected, on purpose
 *
 * These requests come from software on somebody else's machine, which has no session and no token
 * to send. The credential is the licence key, checked on every call. What replaces CSRF here is the
 * throttle below: a key is short enough to be worth guessing, so a caller that starts guessing gets
 * turned away long before they finish.
 */
class ApiController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public $enableCsrfValidation = false;

    /** Requests one address may make in a minute before it is told to slow down. */
    private const RATE_LIMIT = 30;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Edition::allowsActivations(Plugin::getInstance()->isPro())
            || !Plugin::getInstance()->getSettings()->enableActivationApi) {
            $this->asJson([
                'success' => false,
                'code' => ActivationResult::DISABLED,
                'message' => Craft::t('digits', 'Licence activation is switched off.'),
            ])->send();

            return false;
        }

        $this->throttle();

        return true;
    }

    public function actionActivate(): Response
    {
        $result = Plugin::getInstance()->activations->activate(
            $this->key(),
            (string)$this->request->getParam('instance', ''),
            $this->request->getParam('label'),
        );

        return $this->respond($result);
    }

    public function actionDeactivate(): Response
    {
        $result = Plugin::getInstance()->activations->deactivate(
            $this->key(),
            (string)$this->request->getParam('instance', ''),
        );

        return $this->respond($result);
    }

    public function actionCheck(): Response
    {
        $instance = $this->request->getParam('instance');

        $result = Plugin::getInstance()->activations->check(
            $this->key(),
            $instance !== null ? (string)$instance : null,
        );

        return $this->respond($result);
    }

    /**
     * What this licence may update to.
     *
     * The call an auto-updater makes. It returns only the versions the licence is actually
     * entitled to, which means a lapsed customer is told about the build they own rather than
     * being offered one they will then be refused — the update check and the download agree,
     * because both went through the same access service.
     */
    public function actionVersions(): Response
    {
        $plugin = Plugin::getInstance();
        $result = $plugin->activations->check($this->key(), $this->request->getParam('instance'));

        if (!$result->success || $result->license === null) {
            return $this->respond($result);
        }

        $license = $result->license;
        $wanted = $this->request->getParam('download');
        $payload = [];

        foreach ($license->getDownloads() as $download) {
            if ($wanted !== null && (string)$download->sku !== (string)$wanted && (int)$download->id !== (int)$wanted) {
                continue;
            }

            $versions = [];

            foreach ($plugin->versions->getVersionsAvailableTo($download, $license) as $version) {
                $entry = $version->toArray();

                // A URL per file, minted now and short-lived. An updater that stores these and
                // reuses them next month gets a 410 rather than a file, which is correct: the
                // entitlement is checked when the link is made, and a link that outlived its check
                // is a link that outlived a refund.
                foreach ($version->getFiles() as $index => $file) {
                    $entry['files'][$index]['url'] = $plugin->links->urlForFile($file, $version, $download, $license);
                }

                $versions[] = $entry;
            }

            $payload[] = [
                'id' => $download->id,
                'sku' => $download->sku,
                'title' => $download->title,
                'current' => $versions[0]['version'] ?? null,
                'versions' => $versions,
            ];
        }

        return $this->asJson([
            'success' => true,
            'code' => ActivationResult::OK,
            'downloads' => $payload,
        ]);
    }

    private function key(): string
    {
        return trim((string)$this->request->getParam('key', ''));
    }

    private function respond(ActivationResult $result): Response
    {
        $this->response->setStatusCode($result->success ? 200 : 422);

        return $this->asJson($result->toArray());
    }

    /**
     * A per-address budget.
     *
     * Deliberately generous — an updater checking on every page load of a busy site is not an
     * attacker — and deliberately present, because a licence key is a short string and the only
     * thing standing between a guessing script and a working key is how many guesses it gets.
     */
    private function throttle(): void
    {
        $ip = $this->request->getUserIP();

        if ($ip === null) {
            return;
        }

        $cache = Craft::$app->getCache();
        $key = 'digits:api:' . md5($ip) . ':' . floor(time() / 60);
        $count = (int)$cache->get($key);

        if ($count >= self::RATE_LIMIT) {
            throw new TooManyRequestsHttpException(60, Craft::t('digits', 'Too many licence requests. Try again in a minute.'));
        }

        $cache->set($key, $count + 1, 120);
    }
}
