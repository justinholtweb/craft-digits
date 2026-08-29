<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use craft\base\Model;
use DateTime;

/**
 * The result of checking a VAT identification number, and where the answer came from.
 *
 * The `source` is not a detail. A number that passed a checksum is a number that is not a typo; a
 * number VIES confirmed is a number the Commission says belongs to a registered trader today. Only
 * the second is evidence for zero-rating a sale, and an invoice that does not record which of the
 * two it had cannot be defended three years later. So both are stored, and the invoice prints the
 * difference.
 */
class VatIdCheck extends Model
{
    public const SOURCE_FORMAT = 'format';
    public const SOURCE_VIES = 'vies';
    public const SOURCE_MANUAL = 'manual';

    public ?int $id = null;
    public string $vatId = '';
    public string $countryCode = '';
    public ?bool $valid = null;
    public ?string $name = null;
    public ?string $address = null;
    public string $source = self::SOURCE_FORMAT;
    public ?string $consultationNumber = null;
    public ?DateTime $dateChecked = null;
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
        return array_merge(parent::datetimeAttributes(), ['dateChecked']);
    }

    /** Whether this check is good enough to zero-rate a sale on. */
    public function getIsProof(): bool
    {
        return $this->valid === true && $this->source !== self::SOURCE_FORMAT;
    }

    public function getSourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_VIES => \Craft::t('digits', 'Confirmed by VIES'),
            self::SOURCE_MANUAL => \Craft::t('digits', 'Confirmed by hand'),
            default => \Craft::t('digits', 'Format checked only'),
        };
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'vatId' => $this->vatId,
            'countryCode' => $this->countryCode,
            'valid' => $this->valid,
            'name' => $this->name,
            'source' => $this->source,
            'isProof' => $this->getIsProof(),
            'dateChecked' => $this->dateChecked?->format(DATE_ATOM),
        ];
    }
}
