<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\digits\models\AccessVerdict;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\services\Log;
use yii\web\ForbiddenHttpException;
use yii\web\GoneHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handing over the bytes.
 *
 * The only endpoint in Digits that serves a file, and the shortest path through the plugin: redeem
 * the token, ask {@see \justinholtweb\digits\services\Access} exactly what it would ask a signed-in
 * customer, count it, send it. Every decision is somebody else's; this controller's job is to make
 * sure they are all asked, in that order, on every request.
 */
class FileController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    /**
     * Spend a download link.
     *
     * ## Why a range request does not count
     *
     * A download manager fetching a 2 GB file in eight pieces sends eight requests for one
     * download. Counting each of them empties a customer's allowance on their first attempt, and
     * counting a *resumed* download twice does the same thing more slowly. So only a request that
     * is not asking for a continuation counts — no `Range` header at all, or one that starts at
     * byte zero, which is what a fresh download looks like.
     *
     * ## Why the refusal is a 403 and not a redirect
     *
     * A browser that followed a redirect here would put the failure on some other page's URL, and
     * the customer would email support a link to the wrong thing. The refusal is served where it
     * happened, with the verdict's own sentence in it.
     */
    public function actionDownload(string $token): Response
    {
        $plugin = Plugin::getInstance();

        [$row, $verdict] = $plugin->links->redeem($token, Craft::$app->getUser()->getIdentity());

        if (!$verdict->allowed) {
            $plugin->log->denied($verdict, [
                'tokenId' => $row?->id,
                'email' => $row?->email,
            ]);

            throw $this->refusal($verdict);
        }

        $file = $verdict->file;
        $license = $verdict->license;
        $isContinuation = $this->isContinuation();

        // The order matters. The allowance is taken *before* a byte is sent, because a customer
        // who cancels a download halfway has still had the file — and because the alternative is
        // deciding after the response has been streamed, by which point there is nothing to say.
        if (!$isContinuation && $license !== null && !$plugin->licenses->spend($license)) {
            $denied = AccessVerdict::deny(
                AccessVerdict::REASON_LIMIT_REACHED,
                $verdict->download,
                $license,
                $verdict->version,
                $file,
            );

            $plugin->log->denied($denied, ['tokenId' => $row?->id, 'email' => $license->email]);

            throw $this->refusal($denied);
        }

        if (!$isContinuation) {
            $plugin->links->spend($row);

            $plugin->log->write(Log::EVENT_DOWNLOAD, [
                'licenseId' => $license?->id,
                'downloadId' => $verdict->download?->id,
                'versionId' => $verdict->version?->id,
                'fileId' => $file?->id,
                'tokenId' => $row?->id,
                'email' => $license?->email ?? $row?->email,
                'bytes' => $file?->getSize(),
            ]);
        }

        return $plugin->delivery->send($file, $this->response);
    }

    /**
     * Whether this request is continuing a download that has already been counted.
     *
     * `Range: bytes=0-` is a fresh start — plenty of clients send it — so only a range that begins
     * somewhere other than zero is treated as a continuation.
     */
    private function isContinuation(): bool
    {
        $range = Craft::$app->getRequest()->getHeaders()->get('Range');

        if ($range === null) {
            return false;
        }

        return preg_match('/bytes=0-/i', trim($range)) !== 1;
    }

    /**
     * The right status code for a refusal.
     *
     * A 404 for a token nobody has ever seen, a 410 for one that has expired or been spent — the
     * link *was* real and is not any more, which is what Gone means — and a 403 for everything
     * else, which is the plugin saying "this link is fine, you are not allowed".
     */
    private function refusal(AccessVerdict $verdict): \yii\web\HttpException
    {
        $message = $verdict->getMessage();

        return match ($verdict->reason) {
            AccessVerdict::REASON_TOKEN_UNKNOWN, AccessVerdict::REASON_NOT_FOUND => new NotFoundHttpException($message),
            AccessVerdict::REASON_TOKEN_EXPIRED, AccessVerdict::REASON_TOKEN_SPENT => new GoneHttpException($message),
            default => new ForbiddenHttpException($message),
        };
    }
}
