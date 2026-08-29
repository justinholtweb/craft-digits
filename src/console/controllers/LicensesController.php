<?php

declare(strict_types=1);

namespace justinholtweb\digits\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\Plugin;
use yii\console\ExitCode;

/**
 * `php craft digits/licenses/…`
 *
 * The commands a shop needs when something has gone wrong at a scale nobody wants to click through:
 * issuing in bulk after an import, repairing an order whose completion event never fired, and
 * putting the denormalised counters back in step.
 */
class LicensesController extends Controller
{
    /** Who the licence is for. */
    public ?string $email = null;

    /** Comma-separated download IDs or SKUs. */
    public ?string $downloads = null;

    /** Days until it lapses. Omit for the download's own default; 0 for never. */
    public ?int $days = null;

    /** Machines it may run on. 0 for unlimited. */
    public ?int $seats = null;

    /** Say what would happen and change nothing. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'issue' => ['email', 'downloads', 'days', 'seats'],
            'repair' => ['dryRun'],
            default => [],
        };
    }

    /**
     * Issue a licence by hand.
     *
     * `php craft digits/licenses/issue --email=a@b.com --downloads=toolkit,manual`
     */
    public function actionIssue(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->email === null || $this->downloads === null) {
            $this->stderr("Both --email and --downloads are needed.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $downloadIds = [];

        foreach (explode(',', $this->downloads) as $identifier) {
            $identifier = trim($identifier);

            $download = is_numeric($identifier)
                ? $plugin->downloads->getDownloadById((int)$identifier)
                : $plugin->downloads->getDownloadBySku($identifier);

            if ($download === null) {
                $this->stderr("No download matched “$identifier”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $downloadIds[] = (int)$download->id;
        }

        $config = [
            'downloadIds' => $downloadIds,
            'email' => $this->email,
            'source' => License::SOURCE_CONSOLE,
        ];

        if ($this->days !== null) {
            $config['expiryDate'] = $this->days > 0 ? (new DateTime())->modify("+{$this->days} days") : null;
        }

        if ($this->seats !== null) {
            $config['activationLimit'] = $this->seats;
        }

        $license = $plugin->licenses->issue($config);

        if ($license === null) {
            $this->stderr("Couldn’t issue the licence.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Issued {$license->licenseKey} to {$license->email}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Stamp `expired` on to every licence whose date has passed. */
    public function actionExpire(): int
    {
        $count = Plugin::getInstance()->licenses->expireLapsed();

        $this->stdout("Marked $count licences expired.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Put `activationCount` back in step with the activation rows. */
    public function actionRecount(): int
    {
        $count = Plugin::getInstance()->licenses->recountActivations();

        $this->stdout("Recounted $count licences.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Issue the licences that completed orders never got.
     *
     * For the shop that installed Digits after it started selling, or whose completion event was
     * swallowed by a gateway timeout. Safe to run repeatedly: a line item that already has a
     * licence is skipped.
     */
    public function actionRepair(?string $since = null): int
    {
        if (!Plugin::commerceIsReady()) {
            $this->stderr("Commerce isn’t installed, so there are no orders to repair.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $query = \craft\commerce\elements\Order::find()->isCompleted(true)->limit(null);

        if ($since !== null) {
            // `DATE_ATOM`, not a bare `Y-m-d H:i:s`: a bare string is read as system time and
            // converted to UTC a second time, so the window silently matches nothing.
            $query->dateOrdered('>= ' . (new DateTime($since))->format(DATE_ATOM));
        }

        $plugin = Plugin::getInstance();
        $orders = 0;
        $issued = 0;

        foreach ($query->all() as $order) {
            $existing = $plugin->licenses->getLicensesForOrder((int)$order->id);
            $expected = 0;

            foreach ($order->getLineItems() as $lineItem) {
                if ($plugin->orders->downloadsForLineItem($lineItem) !== []) {
                    $expected++;
                }
            }

            if ($expected === 0 || count($existing) >= $expected) {
                continue;
            }

            $orders++;

            if ($this->dryRun) {
                $this->stdout(sprintf("Would issue for %s\n", $order->reference ?: $order->number));
                continue;
            }

            $issued += count($plugin->orders->issueForOrder($order));
        }

        $this->stdout($this->dryRun
            ? "$orders orders are missing licences.\n"
            : "Issued $issued licences across $orders orders.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
