<?php

declare(strict_types=1);

namespace justinholtweb\digits\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\Plugin;
use yii\console\ExitCode;

/**
 * `php craft digits/invoices/…`
 *
 * Raising the invoices a shop forgot to switch on for, and printing the quarter's figures somewhere
 * a cron job can pick them up.
 */
class InvoicesController extends Controller
{
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return $actionID === 'backfill' ? ['dryRun'] : [];
    }

    /**
     * Raise invoices for completed orders that have none.
     *
     * Idempotent — an order with an invoice is skipped — so this is the command to run after
     * switching invoicing on, and again whenever anybody is unsure.
     */
    public function actionBackfill(?string $since = null): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsInvoicing($plugin->isPro())) {
            $this->stderr("VAT invoicing is a Digits Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!Plugin::commerceIsReady()) {
            $this->stderr("Commerce isn’t installed, so there are no orders to invoice.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $query = \craft\commerce\elements\Order::find()->isCompleted(true)->limit(null);

        if ($since !== null) {
            $query->dateOrdered('>= ' . (new DateTime($since))->format(DATE_ATOM));
        }

        $raised = 0;

        foreach ($query->all() as $order) {
            if ($plugin->invoices->getInvoicesForOrder((int)$order->id) !== []) {
                continue;
            }

            if ($this->dryRun) {
                $invoice = $plugin->invoices->buildFromOrder($order);
                $this->stdout(sprintf(
                    "Would raise for %s — %s, %s%%, net %s\n",
                    $order->reference ?: $order->number,
                    $invoice->treatment,
                    $invoice->vatRate,
                    number_format($invoice->netAmount, 2),
                ));
                continue;
            }

            if ($plugin->invoices->issueForOrder($order) !== null) {
                $raised++;
            }
        }

        if (!$this->dryRun) {
            $this->stdout("Raised $raised invoices.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /** The OSS figures for a window, as a table. `--` a quarter with `from` and `to`. */
    public function actionReport(string $from, string $to): int
    {
        $plugin = Plugin::getInstance();
        $rows = $plugin->vat->ossReport(new DateTime($from), new DateTime($to));

        if ($rows === []) {
            $this->stdout("Nothing to report in that window.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-24s %-8s %10s %12s %12s\n", 'Country', 'Rate', 'Invoices', 'Net', 'VAT'));

        $net = 0.0;
        $vat = 0.0;

        foreach ($rows as $row) {
            $net += $row['net'];
            $vat += $row['vat'];

            $this->stdout(sprintf(
                "%-24s %-8s %10d %12s %12s\n",
                $row['countryName'],
                $row['vatRate'] . '%',
                $row['invoices'],
                number_format($row['net'], 2),
                number_format($row['vat'], 2),
            ));
        }

        $this->stdout(sprintf(
            "%-24s %-8s %10s %12s %12s\n",
            'Total',
            '',
            '',
            number_format($net, 2),
            number_format($vat, 2),
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
