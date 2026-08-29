<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;
use justinholtweb\digits\Plugin;

/**
 * A VAT invoice, as issued.
 *
 * Everything on it is a *copy*. The order it was raised from will go on changing — an address is
 * corrected, a line is refunded, a currency rate is restated — and none of that may reach a
 * document somebody has already filed. So the addresses, the lines and every figure are snapshot
 * at issue time, and the only live things on this model are the ones that were never part of the
 * document: which user it belongs to, and whether it has since been credited.
 *
 * The pair of figures at the bottom is the point of the whole class. `vatAmount` is what the
 * determined treatment says was due; `chargedTaxAmount` is what Commerce actually took. Digits
 * never rewrites the second to match the first — a shop's tax engine is the shop's business and
 * quietly restating a charge is how you end up with books that do not reconcile. It records both,
 * flags the gap, and puts the flagged invoices on one screen.
 */
class Invoice extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_VOID = 'void';
    public const STATUS_CREDITED = 'credited';

    public const TREATMENT_DOMESTIC = 'domestic';
    public const TREATMENT_OSS = 'oss';
    public const TREATMENT_REVERSE_CHARGE = 'reverse-charge';
    public const TREATMENT_OUTSIDE_SCOPE = 'outside-scope';

    public const TYPE_B2C = 'b2c';
    public const TYPE_B2B = 'b2b';

    public ?int $id = null;
    public string $number = '';
    public string $series = 'default';
    public ?int $orderId = null;
    public ?string $orderReference = null;
    public ?int $userId = null;
    public ?string $customerEmail = null;
    public ?string $customerName = null;

    public ?string $sellerCountry = null;
    public ?string $sellerVatId = null;

    public ?string $buyerCountry = null;
    public ?string $buyerVatId = null;
    public ?bool $buyerVatIdValid = null;
    public string $buyerType = self::TYPE_B2C;

    public string $treatment = self::TREATMENT_DOMESTIC;
    public float $vatRate = 0.0;
    public ?string $currency = null;
    public float $netAmount = 0.0;
    public float $vatAmount = 0.0;
    public float $grossAmount = 0.0;
    public float $chargedTaxAmount = 0.0;
    public bool $hasDiscrepancy = false;

    public array $evidence = [];
    public array $snapshot = [];

    public string $status = self::STATUS_ISSUED;
    public ?int $creditOfId = null;
    public ?DateTime $dateIssued = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * Columns Craft has to hydrate as dates rather than as strings.
     *
     * Missing one is not a type mismatch you notice — the model loads fine and every date
     * comparison against it silently becomes a string comparison.
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateIssued']);
    }

    public function defineRules(): array
    {
        return [
            [['number', 'dateIssued'], 'required'],
            [['number'], 'string', 'max' => 64],
            [['buyerCountry', 'sellerCountry'], 'string', 'length' => 2, 'skipOnEmpty' => true],
            [['status'], 'in', 'range' => [self::STATUS_ISSUED, self::STATUS_VOID, self::STATUS_CREDITED]],
            [['id', 'series', 'orderId', 'orderReference', 'userId', 'customerEmail', 'customerName',
                'sellerVatId', 'buyerVatId', 'buyerVatIdValid', 'buyerType', 'treatment', 'vatRate',
                'currency', 'netAmount', 'vatAmount', 'grossAmount', 'chargedTaxAmount',
                'hasDiscrepancy', 'evidence', 'snapshot', 'creditOfId', 'uid'], 'safe'],
        ];
    }

    /** @return array[] The lines as they were, each with description, quantity, net, vat and gross. */
    public function getLines(): array
    {
        return $this->snapshot['lines'] ?? [];
    }

    public function getBillingAddress(): array
    {
        return $this->snapshot['billingAddress'] ?? [];
    }

    public function getSeller(): array
    {
        return $this->snapshot['seller'] ?? [];
    }

    public function getIsCreditNote(): bool
    {
        return $this->creditOfId !== null;
    }

    public function getCreditedInvoice(): ?self
    {
        return $this->creditOfId !== null
            ? Plugin::getInstance()->invoices->getInvoiceById($this->creditOfId)
            : null;
    }

    public function getUser(): ?User
    {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    public function getTreatmentLabel(): string
    {
        return match ($this->treatment) {
            self::TREATMENT_OSS => Craft::t('digits', 'Digital services — customer’s country'),
            self::TREATMENT_REVERSE_CHARGE => Craft::t('digits', 'Reverse charge'),
            self::TREATMENT_OUTSIDE_SCOPE => Craft::t('digits', 'Outside the scope of EU VAT'),
            default => Craft::t('digits', 'Domestic'),
        };
    }

    /**
     * The sentence that has to appear on the document itself.
     *
     * An invoice zero-rated under the reverse charge is only valid if it *says so* — the wording
     * is a requirement of the directive, not a courtesy — so this is printed, not optional, and it
     * comes from the treatment rather than from anybody remembering to type it.
     */
    public function getVatNote(): string
    {
        return match ($this->treatment) {
            self::TREATMENT_REVERSE_CHARGE => Craft::t('digits', 'VAT reverse charged — Article 196, Council Directive 2006/112/EC. VAT is to be accounted for by the recipient.'),
            self::TREATMENT_OSS => Craft::t('digits', 'VAT charged at the rate of the customer’s country under the place-of-supply rules for electronically supplied services.'),
            self::TREATMENT_OUTSIDE_SCOPE => Craft::t('digits', 'Outside the scope of EU VAT.'),
            default => '',
        };
    }

    /** The gap between what was due and what was taken, positive when the shop under-charged. */
    public function getDiscrepancyAmount(): float
    {
        return round($this->vatAmount - $this->chargedTaxAmount, 2);
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_VOID => Craft::t('digits', 'Void'),
            self::STATUS_CREDITED => Craft::t('digits', 'Credited'),
            default => Craft::t('digits', 'Issued'),
        };
    }

    /**
     * Whether the evidence behind the buyer's country is as strong as the rules want.
     *
     * Two non-contradictory pieces, is the standard. Digits can offer the billing country and,
     * where a CDN provides it, the country the request came from. When it has only one, it says
     * so here rather than pretending.
     */
    public function getEvidenceIsSufficient(): bool
    {
        $pieces = array_filter([
            $this->evidence['billingCountry'] ?? null,
            $this->evidence['ipCountry'] ?? null,
        ]);

        return count($pieces) >= 2 && count(array_unique($pieces)) === 1;
    }

    public function getEvidenceConflicts(): bool
    {
        $billing = $this->evidence['billingCountry'] ?? null;
        $ip = $this->evidence['ipCountry'] ?? null;

        return $billing !== null && $ip !== null && $billing !== $ip;
    }
}
