<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use craft\base\Model;
use DateTime;

/**
 * A VAT rate for a country, valid between two dates.
 *
 * Dated rather than current, because an invoice raised in March has to keep March's rate after
 * April's takes effect — including when it is reprinted next year for an auditor. Nothing in
 * Digits ever asks "what is the rate in Ireland"; it asks "what was the rate in Ireland on this
 * date", which is a question with a stable answer.
 */
class VatRate extends Model
{
    public const KIND_STANDARD = 'standard';
    public const KIND_REDUCED = 'reduced';
    public const KIND_ZERO = 'zero';

    public ?int $id = null;
    public string $countryCode = '';
    public ?string $name = null;
    public float $rate = 0.0;
    public string $kind = self::KIND_STANDARD;
    public ?DateTime $effectiveFrom = null;
    public ?DateTime $effectiveTo = null;
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
        return array_merge(parent::datetimeAttributes(), ['effectiveFrom', 'effectiveTo']);
    }

    public function defineRules(): array
    {
        return [
            [['countryCode'], 'required'],
            [['countryCode'], 'string', 'length' => 2],
            [['rate'], 'number', 'min' => 0, 'max' => 100],
            [['kind'], 'in', 'range' => [self::KIND_STANDARD, self::KIND_REDUCED, self::KIND_ZERO]],
            [['effectiveTo'], 'validateWindow'],
            [['id', 'name', 'effectiveFrom', 'uid'], 'safe'],
        ];
    }

    public function validateWindow(): void
    {
        if ($this->effectiveFrom !== null
            && $this->effectiveTo !== null
            && $this->effectiveTo->getTimestamp() <= $this->effectiveFrom->getTimestamp()) {
            $this->addError('effectiveTo', \Craft::t('digits', 'The end date has to be after the start date.'));
        }
    }

    public function appliesOn(DateTime $date): bool
    {
        if ($this->effectiveFrom !== null && $this->effectiveFrom->getTimestamp() > $date->getTimestamp()) {
            return false;
        }

        return $this->effectiveTo === null || $this->effectiveTo->getTimestamp() > $date->getTimestamp();
    }

    /** The rate as the multiplier the arithmetic wants: 21 becomes 0.21. */
    public function getMultiplier(): float
    {
        return $this->rate / 100;
    }

    public function getLabel(): string
    {
        return rtrim(rtrim(number_format($this->rate, 2, '.', ''), '0'), '.') . '%';
    }
}
