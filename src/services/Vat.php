<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\models\Invoice;
use justinholtweb\digits\models\VatIdCheck;
use justinholtweb\digits\models\VatRate;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\records\VatIdRecord;
use justinholtweb\digits\records\VatRateRecord;
use Throwable;

/**
 * What VAT treatment a sale gets, and what a VAT number is worth as evidence.
 *
 * ## What this does and does not do
 *
 * It **determines and documents**. It does not charge. Commerce's tax engine decides what a
 * customer pays, and quietly restating that number on the invoice is how a shop ends up with books
 * that do not reconcile and an accountant who does not trust either figure. So Digits works out
 * what the treatment *should* be, prints it, records what was actually taken beside it, and puts
 * the invoices where the two disagree on one screen for somebody to look at.
 *
 * ## The rules it implements
 *
 * Electronically supplied services, which is what a digital download is:
 *
 * - Seller and buyer in the same country → **domestic**, at that country's rate.
 * - Buyer elsewhere in the EU, no valid VAT number → **place of supply is the customer's country**,
 *   at the customer's rate. This is the OSS case.
 * - Buyer elsewhere in the EU with a VAT number confirmed by VIES → **reverse charge**, zero-rated,
 *   with the wording the directive requires printed on the document.
 * - Buyer outside the EU → **outside the scope**.
 *
 * ## Why a VAT number's *source* is stored
 *
 * A number that passes a format check is a number that is not a typo. A number VIES confirmed is a
 * number the Commission says belongs to a registered trader. Only the second is evidence for
 * zero-rating, and an invoice that does not record which it had cannot be defended later. Digits
 * stores both and prints the difference — and it will not claim the reverse charge on a format
 * check alone unless a human has said so.
 */
class Vat extends Component
{
    /** The 27, as the rules understand them. Greece's VAT prefix is `EL`, and only its VAT prefix. */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * Standard rates seeded at install, as published by the Commission at the time of release.
     *
     * A starting point, not gospel: rates change by legislation and a shop that files its own
     * returns should confirm them before its first quarter. They are seeded into a *table* with an
     * effective date precisely so that correcting one is an afternoon in the control panel rather
     * than a plugin release.
     */
    public const SEED_RATES = [
        'AT' => 20.0, 'BE' => 21.0, 'BG' => 20.0, 'HR' => 25.0, 'CY' => 19.0, 'CZ' => 21.0,
        'DK' => 25.0, 'EE' => 24.0, 'FI' => 25.5, 'FR' => 20.0, 'DE' => 19.0, 'GR' => 24.0,
        'HU' => 27.0, 'IE' => 23.0, 'IT' => 22.0, 'LV' => 21.0, 'LT' => 21.0, 'LU' => 17.0,
        'MT' => 18.0, 'NL' => 21.0, 'PL' => 23.0, 'PT' => 23.0, 'RO' => 21.0, 'SK' => 23.0,
        'SI' => 22.0, 'ES' => 21.0, 'SE' => 25.0,
    ];

    /**
     * The structural shape of a VAT number, per country, after the two-letter prefix.
     *
     * Catches transposed digits and pasted-in whitespace, which is most of what goes wrong. It
     * cannot tell you the trader exists — only VIES can — which is exactly why the answer records
     * where it came from.
     */
    private const FORMATS = [
        'AT' => '/^U\d{8}$/',
        'BE' => '/^[01]\d{9}$/',
        'BG' => '/^\d{9,10}$/',
        'HR' => '/^\d{11}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/',
        'DE' => '/^\d{9}$/',
        'GR' => '/^\d{9}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^(\d{7}[A-W]{1,2}|\d[A-Z*+]\d{5}[A-W])$/',
        'IT' => '/^\d{11}$/',
        'LV' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/',
        'SK' => '/^\d{10}$/',
        'SI' => '/^\d{8}$/',
        'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'SE' => '/^\d{12}$/',
    ];

    private const COUNTRY_NAMES = [
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia', 'CY' => 'Cyprus',
        'CZ' => 'Czechia', 'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland', 'FR' => 'France',
        'DE' => 'Germany', 'GR' => 'Greece', 'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy',
        'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta',
        'NL' => 'Netherlands', 'PL' => 'Poland', 'PT' => 'Portugal', 'RO' => 'Romania',
        'SK' => 'Slovakia', 'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden',
    ];

    /** @var VatRate[]|null */
    private ?array $_rates = null;

    public static function countryName(string $code): string
    {
        return self::COUNTRY_NAMES[strtoupper($code)] ?? $code;
    }

    public static function isEuCountry(?string $code): bool
    {
        return $code !== null && in_array(strtoupper($code), self::EU_COUNTRIES, true);
    }

    // Rates
    // -------------------------------------------------------------------------

    /** @return VatRate[] */
    public function getAllRates(): array
    {
        if ($this->_rates === null) {
            $rows = (new Query())
                ->from([Table::VATRATES])
                ->orderBy(['countryCode' => SORT_ASC, 'effectiveFrom' => SORT_DESC])
                ->all();

            $this->_rates = array_map(static fn(array $row) => new VatRate($row), $rows);
        }

        return $this->_rates;
    }

    /**
     * The rate in force in a country on a date.
     *
     * On a *date*, always. Asking for "the current rate" is how an invoice reprinted next year
     * comes out with next year's figure on it.
     */
    public function getRate(string $countryCode, ?DateTime $on = null, string $kind = VatRate::KIND_STANDARD): ?VatRate
    {
        $countryCode = strtoupper($countryCode);
        $on ??= new DateTime();
        $best = null;

        foreach ($this->getAllRates() as $rate) {
            if (strtoupper($rate->countryCode) !== $countryCode || $rate->kind !== $kind || !$rate->appliesOn($on)) {
                continue;
            }

            // Several rows can apply — an open-ended old one and a newly dated one. The latest
            // start date that has already begun is the one in force.
            $bestFrom = $best?->effectiveFrom?->getTimestamp() ?? PHP_INT_MIN;
            $thisFrom = $rate->effectiveFrom?->getTimestamp() ?? PHP_INT_MIN;

            if ($best === null || $thisFrom > $bestFrom) {
                $best = $rate;
            }
        }

        return $best;
    }

    public function saveRate(VatRate $rate, bool $runValidation = true): bool
    {
        if ($runValidation && !$rate->validate()) {
            return false;
        }

        $record = $rate->id !== null ? VatRateRecord::findOne($rate->id) : new VatRateRecord();

        if ($record === null) {
            return false;
        }

        $record->countryCode = strtoupper($rate->countryCode);
        $record->name = $rate->name ?: self::countryName($rate->countryCode);
        $record->rate = (string)$rate->rate;
        $record->kind = $rate->kind;
        $record->effectiveFrom = Db::prepareDateForDb($rate->effectiveFrom);
        $record->effectiveTo = Db::prepareDateForDb($rate->effectiveTo);
        $record->save(false);

        $rate->id = (int)$record->id;
        $this->_rates = null;

        return true;
    }

    public function deleteRateById(int $id): bool
    {
        VatRateRecord::findOne($id)?->delete();
        $this->_rates = null;

        return true;
    }

    // Treatment
    // -------------------------------------------------------------------------

    /**
     * Which of the four cases a sale falls into.
     *
     * `$vatIdIsProof` and not "is there a VAT number": a number typed into a checkout box is a
     * claim, and zero-rating a sale on a claim leaves the shop owing the VAT itself. Only a
     * confirmed one earns the reverse charge.
     */
    public function determineTreatment(
        ?string $sellerCountry,
        ?string $buyerCountry,
        bool $vatIdIsProof = false,
    ): string {
        $sellerCountry = $sellerCountry !== null ? strtoupper($sellerCountry) : null;
        $buyerCountry = $buyerCountry !== null ? strtoupper($buyerCountry) : null;

        if ($buyerCountry === null || !self::isEuCountry($buyerCountry)) {
            return Invoice::TREATMENT_OUTSIDE_SCOPE;
        }

        if ($sellerCountry !== null && $buyerCountry === $sellerCountry) {
            return Invoice::TREATMENT_DOMESTIC;
        }

        return $vatIdIsProof ? Invoice::TREATMENT_REVERSE_CHARGE : Invoice::TREATMENT_OSS;
    }

    /** The rate a treatment implies, in per cent. */
    public function rateForTreatment(string $treatment, ?string $sellerCountry, ?string $buyerCountry, ?DateTime $on = null): float
    {
        return match ($treatment) {
            Invoice::TREATMENT_DOMESTIC => $this->getRate((string)$sellerCountry, $on)?->rate ?? 0.0,
            Invoice::TREATMENT_OSS => $this->getRate((string)$buyerCountry, $on)?->rate ?? 0.0,
            default => 0.0,
        };
    }

    // VAT identification numbers
    // -------------------------------------------------------------------------

    /**
     * Check a VAT number, as well as the site is configured to.
     *
     * Format always; VIES only when the shop has asked for it, and never in a way that can hold up
     * a checkout indefinitely — the timeout is short and a failure falls back to the format answer
     * rather than throwing. A customer must not be unable to buy because the Commission's service
     * is down, which it frequently is.
     */
    public function validateVatId(string $vatId, bool $force = false): VatIdCheck
    {
        [$country, $number] = $this->splitVatId($vatId);
        $normalized = $country . $number;

        $cached = $this->getCachedCheck($normalized);

        if ($cached !== null && !$force && $this->cacheIsFresh($cached)) {
            return $cached;
        }

        $check = new VatIdCheck();
        $check->id = $cached?->id;
        $check->vatId = $normalized;
        $check->countryCode = $this->vatPrefixToCountry($country);
        $check->dateChecked = new DateTime();

        $formatOk = $this->formatIsValid($country, $number);

        $check->valid = $formatOk;
        $check->source = VatIdCheck::SOURCE_FORMAT;

        if ($formatOk && Plugin::getInstance()->getSettings()->validateVatIds) {
            $vies = $this->askVies($country, $number);

            if ($vies !== null) {
                $check->valid = (bool)($vies['valid'] ?? false);
                $check->name = $vies['name'] ?? null;
                $check->address = $vies['address'] ?? null;
                $check->consultationNumber = $vies['consultationNumber'] ?? null;
                $check->source = VatIdCheck::SOURCE_VIES;
            }
        }

        $this->saveCheck($check);

        return $check;
    }

    /** Record a human's decision, which outranks anything a machine said. */
    public function confirmVatIdByHand(string $vatId, bool $valid): VatIdCheck
    {
        [$country, $number] = $this->splitVatId($vatId);

        $check = $this->getCachedCheck($country . $number) ?? new VatIdCheck();
        $check->vatId = $country . $number;
        $check->countryCode = $this->vatPrefixToCountry($country);
        $check->valid = $valid;
        $check->source = VatIdCheck::SOURCE_MANUAL;
        $check->dateChecked = new DateTime();

        $this->saveCheck($check);

        return $check;
    }

    public function formatIsValid(string $prefix, string $number): bool
    {
        $country = strtoupper($prefix);
        $pattern = self::FORMATS[$country] ?? (self::FORMATS[$this->vatPrefixToCountry($country)] ?? null);

        return $pattern !== null && preg_match($pattern, strtoupper($number)) === 1;
    }

    /** `DE123456789` and `de 123 456 789` are the same number; `EL` and `GR` are the same country. */
    public function splitVatId(string $vatId): array
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatId) ?? '');
        $prefix = substr($clean, 0, 2);
        $number = substr($clean, 2);

        return [$prefix, $number];
    }

    public function vatPrefixToCountry(string $prefix): string
    {
        // The one country whose VAT prefix is not its country code. Missing this makes every Greek
        // trader's number fail, and it fails in a way nobody can see from the number itself.
        return strtoupper($prefix) === 'EL' ? 'GR' : strtoupper($prefix);
    }

    public function countryToVatPrefix(string $country): string
    {
        return strtoupper($country) === 'GR' ? 'EL' : strtoupper($country);
    }

    /**
     * Ask VIES.
     *
     * Returns null — not false — when the service could not be reached, because "the Commission is
     * down" and "this number is not registered" are answers a shop has to be able to tell apart.
     * Null makes the caller keep the format answer and record it as a format answer.
     */
    private function askVies(string $prefix, string $number): ?array
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => max(1, $settings->viesTimeout),
                'connect_timeout' => max(1, $settings->viesTimeout),
            ]);

            $response = $client->get(sprintf(
                'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s',
                rawurlencode(strtoupper($prefix)),
                rawurlencode(strtoupper($number)),
            ));

            $body = json_decode((string)$response->getBody(), true);

            if (!is_array($body) || !array_key_exists('isValid', $body)) {
                return null;
            }

            return [
                'valid' => (bool)$body['isValid'],
                'name' => $this->cleanViesValue($body['name'] ?? null),
                'address' => $this->cleanViesValue($body['address'] ?? null),
                'consultationNumber' => $body['requestIdentifier'] ?? null,
            ];
        } catch (Throwable $e) {
            Craft::warning('VIES could not be reached: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /** VIES returns `---` for a trader who has asked not to have their name disclosed. */
    private function cleanViesValue(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' || $value === '---' ? null : $value;
    }

    public function getCachedCheck(string $vatId): ?VatIdCheck
    {
        $row = (new Query())
            ->from([Table::VATIDS])
            ->where(['vatId' => strtoupper($vatId)])
            ->one();

        return $row !== null ? new VatIdCheck($row) : null;
    }

    private function cacheIsFresh(VatIdCheck $check): bool
    {
        // A number confirmed by hand does not go stale — somebody looked at it on purpose.
        if ($check->source === VatIdCheck::SOURCE_MANUAL) {
            return true;
        }

        $days = Plugin::getInstance()->getSettings()->vatIdCacheDays;

        if ($days <= 0 || $check->dateChecked === null) {
            return false;
        }

        return $check->dateChecked->getTimestamp() > time() - ($days * 86400);
    }

    private function saveCheck(VatIdCheck $check): void
    {
        $record = $check->id !== null ? VatIdRecord::findOne($check->id) : null;
        $record ??= VatIdRecord::findOne(['vatId' => $check->vatId]) ?? new VatIdRecord();

        $record->vatId = $check->vatId;
        $record->countryCode = $check->countryCode;
        $record->valid = $check->valid;
        $record->name = $check->name;
        $record->address = $check->address !== null ? mb_substr($check->address, 0, 500) : null;
        $record->source = $check->source;
        $record->consultationNumber = $check->consultationNumber;
        $record->dateChecked = Db::prepareDateForDb($check->dateChecked ?? new DateTime());
        $record->save(false);

        $check->id = (int)$record->id;
    }

    // Reporting
    // -------------------------------------------------------------------------

    /**
     * The OSS return, as a table.
     *
     * One row per country and rate, which is the shape the quarterly return asks for. Reverse
     * charges and out-of-scope sales are excluded from the totals and counted separately, because
     * they belong on the return as a different figure and leaving them in overstates the liability.
     */
    public function ossReport(DateTime $from, DateTime $to): array
    {
        $rows = (new Query())
            ->select([
                'buyerCountry',
                'vatRate',
                'currency',
                'net' => 'SUM([[netAmount]])',
                'vat' => 'SUM([[vatAmount]])',
                'invoices' => 'COUNT(*)',
            ])
            ->from([Table::INVOICES])
            ->where(['treatment' => Invoice::TREATMENT_OSS, 'status' => Invoice::STATUS_ISSUED])
            ->andWhere(['>=', 'dateIssued', Db::prepareDateForDb($from)])
            ->andWhere(['<', 'dateIssued', Db::prepareDateForDb($to)])
            ->groupBy(['buyerCountry', 'vatRate', 'currency'])
            ->orderBy(['buyerCountry' => SORT_ASC])
            ->all();

        return array_map(static function(array $row) {
            $row['countryName'] = self::countryName((string)$row['buyerCountry']);
            $row['net'] = (float)$row['net'];
            $row['vat'] = (float)$row['vat'];
            $row['vatRate'] = (float)$row['vatRate'];
            $row['invoices'] = (int)$row['invoices'];

            return $row;
        }, $rows);
    }

    /** The other three treatments, summarised, so a return has the whole quarter accounted for. */
    public function treatmentTotals(DateTime $from, DateTime $to): array
    {
        $rows = (new Query())
            ->select([
                'treatment',
                'currency',
                'net' => 'SUM([[netAmount]])',
                'vat' => 'SUM([[vatAmount]])',
                'invoices' => 'COUNT(*)',
            ])
            ->from([Table::INVOICES])
            ->where(['status' => Invoice::STATUS_ISSUED])
            ->andWhere(['>=', 'dateIssued', Db::prepareDateForDb($from)])
            ->andWhere(['<', 'dateIssued', Db::prepareDateForDb($to)])
            ->groupBy(['treatment', 'currency'])
            ->all();

        return array_map(static function(array $row) {
            $row['net'] = (float)$row['net'];
            $row['vat'] = (float)$row['vat'];
            $row['invoices'] = (int)$row['invoices'];

            return $row;
        }, $rows);
    }

    /** When the seeded rates were written, so the VAT screen can say how old they are. */
    public function getSeedDate(): ?DateTime
    {
        $value = (new Query())
            ->select(['dateCreated'])
            ->from([Table::VATRATES])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->scalar();

        return $value !== false && $value !== null
            ? new DateTime((string)$value, new DateTimeZone('UTC'))
            : null;
    }
}
