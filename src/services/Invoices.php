<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\Invoice;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\InvoiceRecord;
use Throwable;

/**
 * Raising VAT invoices from Commerce orders.
 *
 * ## Numbers
 *
 * A VAT invoice number has to be **sequential and gapless within its series**, which is a stronger
 * promise than "unique". So the counter is a row, incremented under a named lock, and the number is
 * allocated at the moment the invoice is written rather than derived from the order — an order
 * reference is not sequential and never was.
 *
 * The counter is bucketed by reset period exactly the way Abacus buckets order numbers: `''`,
 * `2026` or `2026-08`, one row each, so "restart every year" means something.
 *
 * ## Snapshots
 *
 * Everything printed on an invoice is copied on to it at issue time — the addresses, the lines,
 * every figure. The order will go on changing after the document exists, and none of that may reach
 * a piece of paper somebody has already filed with their return.
 *
 * ## Corrections are credit notes
 *
 * Nothing here edits an issued invoice, and nothing deletes one. A mistake is corrected by issuing
 * a credit note that points at it, which is both what the rules require and the only version of
 * events that survives an audit.
 */
class Invoices extends Component
{
    public function getInvoiceById(int $id): ?Invoice
    {
        $row = (new Query())->from([Table::INVOICES])->where(['id' => $id])->one();

        return $row !== null ? $this->rowToInvoice($row) : null;
    }

    public function getInvoiceByNumber(string $number): ?Invoice
    {
        $row = (new Query())->from([Table::INVOICES])->where(['number' => $number])->one();

        return $row !== null ? $this->rowToInvoice($row) : null;
    }

    /** @return Invoice[] */
    public function getInvoicesForOrder(int $orderId): array
    {
        $rows = (new Query())
            ->from([Table::INVOICES])
            ->where(['orderId' => $orderId])
            ->orderBy(['dateIssued' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->rowToInvoice($row), $rows);
    }

    /** @return Invoice[] Everything a customer may see in their portal. */
    public function getInvoicesForEmail(string $email): array
    {
        $rows = (new Query())
            ->from([Table::INVOICES])
            ->where(['customerEmail' => $email])
            ->orderBy(['dateIssued' => SORT_DESC])
            ->all();

        return array_map(fn(array $row) => $this->rowToInvoice($row), $rows);
    }

    /** @return Invoice[] */
    public function find(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = (new Query())->from([Table::INVOICES]);

        foreach (['status', 'treatment', 'buyerCountry', 'series', 'orderId'] as $key) {
            if (isset($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        if (!empty($criteria['discrepanciesOnly'])) {
            $query->andWhere(['hasDiscrepancy' => true]);
        }

        if (isset($criteria['from'])) {
            $query->andWhere(['>=', 'dateIssued', Db::prepareDateForDb($criteria['from'])]);
        }

        if (isset($criteria['to'])) {
            $query->andWhere(['<', 'dateIssued', Db::prepareDateForDb($criteria['to'])]);
        }

        if (isset($criteria['search'])) {
            $search = '%' . trim((string)$criteria['search']) . '%';
            $query->andWhere(['or',
                ['like', 'number', $search, false],
                ['like', 'customerEmail', $search, false],
                ['like', 'customerName', $search, false],
                ['like', 'orderReference', $search, false],
            ]);
        }

        $rows = $query
            ->orderBy(['dateIssued' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row) => $this->rowToInvoice($row), $rows);
    }

    public function count(array $criteria = []): int
    {
        $query = (new Query())->from([Table::INVOICES]);

        foreach (['status', 'treatment', 'buyerCountry', 'series'] as $key) {
            if (isset($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        if (!empty($criteria['discrepanciesOnly'])) {
            $query->andWhere(['hasDiscrepancy' => true]);
        }

        return (int)$query->count();
    }

    /**
     * Raise an invoice for a completed order.
     *
     * Idempotent: an order that already has one gets it back rather than a second document with a
     * second number. That matters because the thing calling this is an event handler, and event
     * handlers run twice more often than anybody plans for.
     */
    public function issueForOrder(Order $order, bool $force = false): ?Invoice
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enableInvoicing || !Edition::allowsInvoicing($plugin->isPro())) {
            return null;
        }

        if (!$force) {
            $existing = $this->getInvoicesForOrder((int)$order->id);

            foreach ($existing as $invoice) {
                if (!$invoice->getIsCreditNote() && $invoice->status !== Invoice::STATUS_VOID) {
                    return $invoice;
                }
            }
        }

        $invoice = $this->buildFromOrder($order);

        return $this->save($invoice) ? $invoice : null;
    }

    /**
     * Work out what an invoice for this order says, without writing anything.
     *
     * Public because the settings screen previews it and the tests assert on it: being able to ask
     * "what would you print for this order" without raising a document is the difference between a
     * tax feature somebody can check and one they have to trust.
     */
    public function buildFromOrder(Order $order): Invoice
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $vat = $plugin->vat;

        $billing = $order->getBillingAddress();
        $buyerCountry = $billing?->countryCode;
        $buyerVatId = $this->vatIdFromOrder($order);

        $check = $buyerVatId !== null ? $vat->validateVatId($buyerVatId) : null;
        $isProof = $check?->getIsProof() ?? false;

        $treatment = $vat->determineTreatment($settings->sellerCountry, $buyerCountry, $isProof);
        $issued = new DateTime();
        $rate = $vat->rateForTreatment($treatment, $settings->sellerCountry, $buyerCountry, $issued);

        $chargedTax = (float)$order->getTotalTax() + (float)$order->getTotalTaxIncluded();
        $gross = (float)$order->getTotalPrice();
        $net = round($gross - $chargedTax, 2);
        $dueVat = round($net * ($rate / 100), 2);

        $invoice = new Invoice();
        $invoice->series = $settings->invoiceSeries;
        $invoice->orderId = (int)$order->id;
        $invoice->orderReference = $order->reference ?: $order->number;
        $invoice->userId = $order->getCustomer()?->id;
        $invoice->customerEmail = $order->getEmail();
        $invoice->customerName = trim(sprintf('%s %s', $billing?->firstName ?? '', $billing?->lastName ?? '')) ?: ($billing?->fullName ?: null);
        $invoice->sellerCountry = $settings->sellerCountry ?: null;
        $invoice->sellerVatId = $settings->sellerVatId ?: null;
        $invoice->buyerCountry = $buyerCountry;
        $invoice->buyerVatId = $check?->vatId ?? $buyerVatId;
        $invoice->buyerVatIdValid = $check?->valid;
        $invoice->buyerType = $isProof ? Invoice::TYPE_B2B : Invoice::TYPE_B2C;
        $invoice->treatment = $treatment;
        $invoice->vatRate = $rate;
        $invoice->currency = $order->currency;
        $invoice->netAmount = $net;
        $invoice->vatAmount = $dueVat;
        $invoice->grossAmount = $gross;
        $invoice->chargedTaxAmount = round($chargedTax, 2);

        // A penny of rounding is not a discrepancy; a rate applied that nobody expected is. The
        // tolerance is deliberately tiny — this flag exists to be noticed, not to be quiet.
        $invoice->hasDiscrepancy = abs($dueVat - $chargedTax) > 0.01;

        $invoice->evidence = $this->gatherEvidence($order, $billing, $check);
        $invoice->snapshot = $this->snapshotOrder($order);
        $invoice->dateIssued = $issued;
        $invoice->status = Invoice::STATUS_ISSUED;

        return $invoice;
    }

    /**
     * A credit note against an issued invoice.
     *
     * Its own number in the same series, its own row, every figure negated, and a pointer back to
     * what it corrects. The original is marked credited and otherwise left exactly as it was.
     */
    public function credit(Invoice $invoice, ?float $amount = null, ?string $reason = null): ?Invoice
    {
        if ($invoice->id === null || $invoice->getIsCreditNote()) {
            return null;
        }

        $ratio = $amount !== null && $invoice->grossAmount > 0
            ? min(1.0, $amount / $invoice->grossAmount)
            : 1.0;

        $credit = new Invoice();
        $credit->series = $invoice->series;
        $credit->orderId = $invoice->orderId;
        $credit->orderReference = $invoice->orderReference;
        $credit->userId = $invoice->userId;
        $credit->customerEmail = $invoice->customerEmail;
        $credit->customerName = $invoice->customerName;
        $credit->sellerCountry = $invoice->sellerCountry;
        $credit->sellerVatId = $invoice->sellerVatId;
        $credit->buyerCountry = $invoice->buyerCountry;
        $credit->buyerVatId = $invoice->buyerVatId;
        $credit->buyerVatIdValid = $invoice->buyerVatIdValid;
        $credit->buyerType = $invoice->buyerType;
        $credit->treatment = $invoice->treatment;
        $credit->vatRate = $invoice->vatRate;
        $credit->currency = $invoice->currency;
        $credit->netAmount = -round($invoice->netAmount * $ratio, 2);
        $credit->vatAmount = -round($invoice->vatAmount * $ratio, 2);
        $credit->grossAmount = -round($invoice->grossAmount * $ratio, 2);
        $credit->chargedTaxAmount = -round($invoice->chargedTaxAmount * $ratio, 2);
        $credit->evidence = $invoice->evidence;
        $credit->snapshot = array_merge($invoice->snapshot, [
            'creditReason' => $reason,
            'creditOf' => $invoice->number,
            'creditRatio' => $ratio,
        ]);
        $credit->creditOfId = $invoice->id;
        $credit->dateIssued = new DateTime();

        if (!$this->save($credit)) {
            return null;
        }

        $invoice->status = Invoice::STATUS_CREDITED;
        $this->save($invoice);

        return $credit;
    }

    public function save(Invoice $invoice, bool $runValidation = true): bool
    {
        $isNew = $invoice->id === null;

        if ($isNew && $invoice->number === '') {
            $invoice->number = $this->nextNumber($invoice->series, $invoice->dateIssued);
        }

        if ($runValidation && !$invoice->validate()) {
            return false;
        }

        $record = $isNew ? new InvoiceRecord() : InvoiceRecord::findOne($invoice->id);

        if ($record === null) {
            return false;
        }

        $record->number = $invoice->number;
        $record->series = $invoice->series;
        $record->orderId = $invoice->orderId;
        $record->orderReference = $invoice->orderReference;
        $record->userId = $invoice->userId;
        $record->customerEmail = $invoice->customerEmail;
        $record->customerName = $invoice->customerName;
        $record->sellerCountry = $invoice->sellerCountry;
        $record->sellerVatId = $invoice->sellerVatId;
        $record->buyerCountry = $invoice->buyerCountry;
        $record->buyerVatId = $invoice->buyerVatId;
        $record->buyerVatIdValid = $invoice->buyerVatIdValid;
        $record->buyerType = $invoice->buyerType;
        $record->treatment = $invoice->treatment;
        $record->vatRate = (string)$invoice->vatRate;
        $record->currency = $invoice->currency;
        $record->netAmount = (string)$invoice->netAmount;
        $record->vatAmount = (string)$invoice->vatAmount;
        $record->grossAmount = (string)$invoice->grossAmount;
        $record->chargedTaxAmount = (string)$invoice->chargedTaxAmount;
        $record->hasDiscrepancy = $invoice->hasDiscrepancy;
        $record->evidence = $invoice->evidence !== [] ? Json::encode($invoice->evidence) : null;
        $record->snapshot = $invoice->snapshot !== [] ? Json::encode($invoice->snapshot) : null;
        $record->status = $invoice->status;
        $record->creditOfId = $invoice->creditOfId;
        $record->dateIssued = Db::prepareDateForDb($invoice->dateIssued ?? new DateTime());
        $record->save(false);

        $invoice->id = (int)$record->id;
        $invoice->uid = $record->uid;

        return true;
    }

    /**
     * The next number in a series.
     *
     * Under a mutex, because "sequential and gapless" is a promise two simultaneous checkouts can
     * otherwise break, and because a duplicate invoice number is the one thing an accountant will
     * definitely notice. The unique index on `number` is the backstop if the lock is ever
     * unavailable.
     */
    public function nextNumber(string $series, ?DateTime $date = null): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $date ??= new DateTime();
        $periodKey = $this->periodKey($settings->invoiceNumberReset, $date);
        $mutex = Craft::$app->getMutex();
        $lock = sprintf('digits:invoice:%s:%s', $series, $periodKey);

        $acquired = $mutex->acquire($lock, 5);

        try {
            // An invoice number has to be unique across the *business*, not merely within its
            // series — which is why the column carries a plain unique index and not a composite
            // one. A format with no `{series}` token in it will therefore collide the moment a
            // shop starts a second series, so the counter is bumped until the number is free
            // rather than the constraint being weakened to let the collision through.
            for ($attempt = 0; $attempt < 1000; $attempt++) {
                $counter = $this->bumpCounter($series, $periodKey, $settings->invoiceStartNumber);
                $number = $this->formatNumber($settings, $series, $date, $counter);

                if (!$this->numberExists($number)) {
                    return $number;
                }
            }

            throw new \RuntimeException(sprintf(
                'Could not find a free invoice number in series “%s”. Add a {series} token to the invoice number format.',
                $series,
            ));
        } finally {
            if ($acquired) {
                $mutex->release($lock);
            }
        }
    }

    /** What the next number *would* be, for the settings screen. Spends nothing. */
    public function previewNumber(string $series, ?DateTime $date = null): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $date ??= new DateTime();
        $periodKey = $this->periodKey($settings->invoiceNumberReset, $date);

        $current = (int)((new Query())
            ->select(['counter'])
            ->from([Table::INVOICE_COUNTERS])
            ->where(['series' => $series, 'periodKey' => $periodKey])
            ->scalar() ?: 0);

        $next = $current > 0 ? $current + 1 : max(1, $settings->invoiceStartNumber);

        while ($this->numberExists($number = $this->formatNumber($settings, $series, $date, $next))) {
            $next++;
        }

        return $number;
    }

    private function formatNumber(\justinholtweb\digits\models\Settings $settings, string $series, DateTime $date, int $counter): string
    {
        return strtr($settings->invoiceNumberFormat, [
            '{series}' => $series,
            '{year}' => $date->format('Y'),
            '{month}' => $date->format('m'),
            '{number}' => str_pad((string)$counter, max(1, $settings->invoiceNumberPadding), '0', STR_PAD_LEFT),
        ]);
    }

    private function numberExists(string $number): bool
    {
        return (new Query())->from([Table::INVOICES])->where(['number' => $number])->exists();
    }

    public function periodKey(string $reset, DateTime $date): string
    {
        return match ($reset) {
            'yearly' => $date->format('Y'),
            'monthly' => $date->format('Y-m'),
            default => '',
        };
    }

    /**
     * Increment the counter and hand back what it became.
     *
     * `UPDATE … SET counter = counter + 1` and then a read, rather than a read and then a write:
     * the increment is atomic in the database even without the lock above, so the lock is protecting
     * the *format* — the read that follows — rather than the arithmetic.
     */
    private function bumpCounter(string $series, string $periodKey, int $startAt): int
    {
        $db = Craft::$app->getDb();

        $affected = (int)$db->createCommand()
            ->update(
                Table::INVOICE_COUNTERS,
                ['counter' => new \yii\db\Expression('[[counter]] + 1')],
                ['series' => $series, 'periodKey' => $periodKey],
            )
            ->execute();

        if ($affected === 0) {
            // No row yet for this series and period. The insert is safe under the lock, and the
            // unique index on (series, periodKey) is what stops a second one if the lock was never
            // acquired.
            $now = Db::prepareDateForDb(new DateTime());
            $counter = max(1, $startAt);

            $db->createCommand()
                ->insert(Table::INVOICE_COUNTERS, [
                    'series' => $series,
                    'periodKey' => $periodKey,
                    'counter' => $counter,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])
                ->execute();

            return $counter;
        }

        return (int)((new Query())
            ->select(['counter'])
            ->from([Table::INVOICE_COUNTERS])
            ->where(['series' => $series, 'periodKey' => $periodKey])
            ->scalar() ?: 1);
    }

    /**
     * Where the buyer's VAT number comes from.
     *
     * `organizationTaxId` on the billing address — Craft's own field for exactly this, so a shop
     * gets a VAT box in its checkout by enabling an address field rather than by installing
     * anything. A custom field named `vatId` is honoured too, for the sites that got there first.
     */
    private function vatIdFromOrder(Order $order): ?string
    {
        $billing = $order->getBillingAddress();

        if ($billing === null) {
            return null;
        }

        $value = trim((string)($billing->organizationTaxId ?? ''));

        if ($value !== '') {
            return $value;
        }

        try {
            $custom = $billing->getFieldValue('vatId');

            if (is_string($custom) && trim($custom) !== '') {
                return trim($custom);
            }
        } catch (Throwable) {
            // No such field, which is the normal case.
        }

        return null;
    }

    /**
     * The two pieces of evidence the place-of-supply rules ask for.
     *
     * Digits will not do a geo-IP lookup of its own — that is an outbound request on every
     * checkout and a database to keep current — so the second piece comes from a header a CDN
     * sets. When there is only one, the invoice records that there was only one rather than
     * implying otherwise.
     */
    private function gatherEvidence(Order $order, mixed $billing, mixed $check): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $request = Craft::$app->getRequest();
        $ipCountry = null;

        if (!$request->getIsConsoleRequest() && $settings->countryHeader !== '') {
            $header = trim((string)$request->getHeaders()->get($settings->countryHeader));
            $ipCountry = $header !== '' && strlen($header) === 2 ? strtoupper($header) : null;
        }

        return array_filter([
            'billingCountry' => $billing?->countryCode,
            'ipCountry' => $ipCountry,
            'ip' => $request->getIsConsoleRequest() ? null : $request->getUserIP(),
            'currency' => $order->currency,
            'vatIdSource' => $check?->source,
            'vatIdCheckedDate' => $check?->dateChecked?->format(DATE_ATOM),
            'orderDate' => $order->dateOrdered?->format(DATE_ATOM),
        ], static fn($value) => $value !== null);
    }

    /** Every figure and every address, as they stood. */
    private function snapshotOrder(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $lines = [];

        foreach ($order->getLineItems() as $item) {
            $lines[] = [
                'description' => $item->getDescription(),
                'sku' => $item->getSku(),
                'qty' => (int)$item->qty,
                'unitPrice' => (float)$item->salePrice,
                'net' => (float)$item->getSubtotal(),
                'tax' => (float)$item->getTax() + (float)$item->getTaxIncluded(),
                'total' => (float)$item->getTotal(),
            ];
        }

        $adjustments = [];

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->lineItemId !== null) {
                continue;
            }

            $adjustments[] = [
                'type' => $adjustment->type,
                'name' => $adjustment->name,
                'description' => $adjustment->description,
                'amount' => (float)$adjustment->amount,
            ];
        }

        $billing = $order->getBillingAddress();

        return [
            'lines' => $lines,
            'adjustments' => $adjustments,
            'billingAddress' => $billing !== null ? [
                'name' => trim(sprintf('%s %s', $billing->firstName ?? '', $billing->lastName ?? '')),
                'organization' => $billing->organization,
                'organizationTaxId' => $billing->organizationTaxId,
                'formatted' => implode(', ', array_filter(array_map('trim', explode(
                    "\n",
                    Craft::$app->getAddresses()->formatAddress($billing, ['html' => false]),
                )))),
                'countryCode' => $billing->countryCode,
            ] : null,
            'seller' => [
                'name' => $settings->sellerName,
                'address' => $settings->sellerAddress,
                'country' => $settings->sellerCountry,
                'vatId' => $settings->sellerVatId,
                'registration' => $settings->sellerRegistration,
            ],
            'orderNumber' => $order->number,
            'orderReference' => $order->reference,
            'paymentCurrency' => $order->paymentCurrency,
            'totals' => [
                'itemSubtotal' => (float)$order->getItemSubtotal(),
                'totalDiscount' => (float)$order->getTotalDiscount(),
                'totalShipping' => (float)$order->getTotalShippingCost(),
                'totalTax' => (float)$order->getTotalTax(),
                'totalTaxIncluded' => (float)$order->getTotalTaxIncluded(),
                'totalPrice' => (float)$order->getTotalPrice(),
            ],
        ];
    }

    private function rowToInvoice(array $row): Invoice
    {
        foreach (['evidence', 'snapshot'] as $key) {
            $row[$key] = $row[$key] !== null ? (Json::decodeIfJson($row[$key]) ?: []) : [];
        }

        foreach (['vatRate', 'netAmount', 'vatAmount', 'grossAmount', 'chargedTaxAmount'] as $key) {
            $row[$key] = (float)$row[$key];
        }

        $row['hasDiscrepancy'] = (bool)$row['hasDiscrepancy'];

        if ($row['buyerVatIdValid'] !== null) {
            $row['buyerVatIdValid'] = (bool)$row['buyerVatIdValid'];
        }

        return new Invoice($row);
    }
}
