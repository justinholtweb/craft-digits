<?php

declare(strict_types=1);

namespace justinholtweb\digits\records;

use craft\db\ActiveRecord;
use justinholtweb\digits\db\Table;

/**
 * A VAT invoice, as issued. Every figure on it is a copy.
 *
 * @property int $id
 * @property string $number
 * @property string $series
 * @property int|null $orderId
 * @property string|null $orderReference
 * @property int|null $userId
 * @property string|null $customerEmail
 * @property string|null $customerName
 * @property string|null $sellerCountry
 * @property string|null $sellerVatId
 * @property string|null $buyerCountry
 * @property string|null $buyerVatId
 * @property bool|null $buyerVatIdValid
 * @property string $buyerType
 * @property string $treatment
 * @property string $vatRate
 * @property string|null $currency
 * @property string $netAmount
 * @property string $vatAmount
 * @property string $grossAmount
 * @property string $chargedTaxAmount
 * @property bool $hasDiscrepancy
 * @property string|null $evidence
 * @property string|null $snapshot
 * @property string $status
 * @property int|null $creditOfId
 * @property string $dateIssued
 */
class InvoiceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::INVOICES;
    }
}
