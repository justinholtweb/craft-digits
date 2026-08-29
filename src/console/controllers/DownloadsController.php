<?php

declare(strict_types=1);

namespace justinholtweb\digits\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Queue;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\queue\jobs\NotifyVersion;
use yii\console\ExitCode;

/**
 * `php craft digits/downloads/…`
 *
 * Housekeeping over the catalogue itself: telling licensees about a release that was published
 * without notices, clearing out spent links, and checking that every file a customer could ask for
 * is actually there.
 */
class DownloadsController extends Controller
{
    /** Send the notices for a release that went out quietly. */
    public function actionNotify(int $versionId): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsUpdateNotices($plugin->isPro())) {
            $this->stderr("Release notices are a Digits Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $version = $plugin->versions->getVersionById($versionId);

        if ($version === null || !$version->getIsLive()) {
            $this->stderr("No released version with that ID.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        Queue::push(new NotifyVersion(['versionId' => $versionId]));

        $this->stdout("Queued release notices for {$version->version}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Delete expired download links. Housekeeping — a redemption checks the date itself. */
    public function actionPruneLinks(): int
    {
        $count = Plugin::getInstance()->links->prune();

        $this->stdout("Deleted $count expired links.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionPruneLog(?int $days = null): int
    {
        $count = Plugin::getInstance()->log->prune($days);

        $this->stdout("Deleted $count log rows.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Check that every live version has files, and that every file still has its bytes.
     *
     * The failure this catches is quiet and expensive: an asset deleted from a volume leaves a file
     * row pointing at nothing, and nobody finds out until a paying customer clicks the button.
     */
    public function actionCheck(): int
    {
        $plugin = Plugin::getInstance();
        $problems = 0;

        foreach ($plugin->downloads->getAllDownloads() as $download) {
            $versions = $download->getVersions(false);

            if ($versions === []) {
                $this->stdout(sprintf("· %s has no released version\n", $download->title), Console::FG_YELLOW);
                $problems++;
                continue;
            }

            foreach ($versions as $version) {
                if ($version->getFiles() === []) {
                    $this->stdout(sprintf("· %s %s has no files\n", $download->title, $version->version), Console::FG_YELLOW);
                    $problems++;
                }

                foreach ($version->getFiles() as $file) {
                    if (!$file->getIsAvailable()) {
                        $this->stdout(sprintf(
                            "· %s %s — “%s” points at a missing asset\n",
                            $download->title,
                            $version->version,
                            $file->name,
                        ), Console::FG_RED);
                        $problems++;
                    }
                }
            }
        }

        if ($problems === 0) {
            $this->stdout("Every download has a released version and every file is there.\n", Console::FG_GREEN);
        }

        return $problems === 0 ? ExitCode::OK : ExitCode::DATAERR;
    }
}
