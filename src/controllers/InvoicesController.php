<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\Invoice;
use justinholtweb\digits\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Invoices and the VAT return.
 *
 * The screen that earns its keep is {@see actionReport()} — the quarterly OSS figures, by country
 * and rate, in the shape the return asks for, downloadable as a CSV somebody can hand to an
 * accountant. Everything else here is looking things up.
 *
 * Nothing edits an issued invoice. A mistake is corrected with a credit note, which is what the
 * rules require and the only version of events that survives being audited.
 */
class InvoicesController extends Controller
{
    private const PER_PAGE = 50;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_INVOICES);

        if (!Edition::allowsInvoicing(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException(Craft::t('digits', 'VAT invoicing is a Digits Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $page = max(1, (int)$this->request->getParam('page', 1));

        $criteria = array_filter([
            'status' => $this->request->getParam('status') ?: null,
            'treatment' => $this->request->getParam('treatment') ?: null,
            'buyerCountry' => $this->request->getParam('country') ?: null,
            'search' => $this->request->getParam('search') ?: null,
            'discrepanciesOnly' => (bool)$this->request->getParam('discrepancies'),
        ]);

        $total = $plugin->invoices->count($criteria);

        return $this->renderTemplate('digits/invoices/_index', [
            'invoices' => $plugin->invoices->find($criteria, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pageCount' => (int)ceil($total / self::PER_PAGE),
            'criteria' => $criteria,
            'discrepancyCount' => $plugin->invoices->count(['discrepanciesOnly' => true]),
            'treatments' => [
                '' => Craft::t('digits', 'Every treatment'),
                Invoice::TREATMENT_DOMESTIC => Craft::t('digits', 'Domestic'),
                Invoice::TREATMENT_OSS => Craft::t('digits', 'Customer’s country'),
                Invoice::TREATMENT_REVERSE_CHARGE => Craft::t('digits', 'Reverse charge'),
                Invoice::TREATMENT_OUTSIDE_SCOPE => Craft::t('digits', 'Outside the scope'),
            ],
        ]);
    }

    public function actionDetail(int $invoiceId): Response
    {
        $invoice = Plugin::getInstance()->invoices->getInvoiceById($invoiceId);

        if ($invoice === null) {
            throw new NotFoundHttpException('Invoice not found.');
        }

        return $this->renderTemplate('digits/invoices/_detail', [
            'invoice' => $invoice,
            'settings' => Plugin::getInstance()->getSettings(),
            'title' => $invoice->number,
        ]);
    }

    /**
     * The quarter, by country and rate.
     *
     * Defaults to the quarter that has just ended rather than the one in progress, because the only
     * reason anybody opens this screen is that a return is due.
     */
    public function actionReport(): Response
    {
        $plugin = Plugin::getInstance();
        [$from, $to] = $this->window();

        $rows = $plugin->vat->ossReport($from, $to);
        $totals = $plugin->vat->treatmentTotals($from, $to);

        if ($this->request->getParam('csv')) {
            return $this->csv($rows, $from, $to);
        }

        return $this->renderTemplate('digits/invoices/_report', [
            'rows' => $rows,
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'net' => array_sum(array_column($rows, 'net')),
            'vat' => array_sum(array_column($rows, 'vat')),
        ]);
    }

    public function actionCredit(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $invoice = $plugin->invoices->getInvoiceById((int)$this->request->getRequiredBodyParam('invoiceId'));

        if ($invoice === null) {
            throw new NotFoundHttpException('Invoice not found.');
        }

        $amount = $this->request->getBodyParam('amount');
        $credit = $plugin->invoices->credit(
            $invoice,
            $amount !== null && $amount !== '' ? (float)$amount : null,
            $this->request->getBodyParam('reason') ?: null,
        );

        if ($credit === null) {
            $this->setFailFlash(Craft::t('digits', 'Couldn’t raise the credit note.'));

            return $this->redirect(UrlHelper::cpUrl('digits/invoices/' . $invoice->id));
        }

        return $this->asSuccess(
            Craft::t('digits', 'Credit note {number} raised.', ['number' => $credit->number]),
            [],
            UrlHelper::cpUrl('digits/invoices/' . $credit->id),
        );
    }

    /** Raise an invoice for an order that never got one — a shop that switched invoicing on late. */
    public function actionIssue(): Response
    {
        $this->requirePostRequest();

        if (!Plugin::commerceIsReady()) {
            throw new NotFoundHttpException();
        }

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = \craft\commerce\elements\Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        $invoice = Plugin::getInstance()->invoices->issueForOrder($order, (bool)$this->request->getBodyParam('force'));

        if ($invoice === null) {
            $this->setFailFlash(Craft::t('digits', 'Couldn’t raise an invoice for that order.'));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('digits', 'Invoice {number} raised.', ['number' => $invoice->number]));

        return $this->redirectToPostedUrl();
    }

    /** @return DateTime[] */
    private function window(): array
    {
        $from = $this->request->getParam('from');
        $to = $this->request->getParam('to');

        if ($from && $to) {
            return [
                DateTimeHelper::toDateTime($from) ?: new DateTime('-3 months'),
                DateTimeHelper::toDateTime($to) ?: new DateTime(),
            ];
        }

        $now = new DateTime();
        $quarter = (int)ceil(((int)$now->format('n')) / 3);
        $previous = $quarter - 1;
        $year = (int)$now->format('Y');

        if ($previous < 1) {
            $previous = 4;
            $year--;
        }

        $startMonth = ($previous - 1) * 3 + 1;
        $start = new DateTime(sprintf('%d-%02d-01 00:00:00', $year, $startMonth));

        return [$start, (clone $start)->modify('+3 months')];
    }

    private function csv(array $rows, DateTime $from, DateTime $to): Response
    {
        $out = fopen('php://temp', 'r+');

        fputcsv($out, ['Country', 'Country code', 'VAT rate', 'Currency', 'Invoices', 'Net', 'VAT']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['countryName'],
                $row['buyerCountry'],
                $row['vatRate'],
                $row['currency'],
                $row['invoices'],
                number_format($row['net'], 2, '.', ''),
                number_format($row['vat'], 2, '.', ''),
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response->sendContentAsFile(
            $csv,
            sprintf('oss-%s-%s.csv', $from->format('Y-m-d'), $to->format('Y-m-d')),
            ['mimeType' => 'text/csv'],
        );
    }
}
