<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * One seat on a licence.
 *
 * The `instance` is whatever the customer's software calls itself — a site URL, a machine
 * fingerprint, a container id. Digits deliberately has no opinion about the format, because every
 * vendor's is different and the only property that matters is that the same installation sends the
 * same string twice. Normalising it (case, trailing slash, `www.`) happens once, here, so that
 * `https://Example.com/` and `example.com` are one seat rather than two.
 */
class Activation extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEACTIVATED = 'deactivated';

    public ?int $id = null;
    public ?int $licenseId = null;
    public string $instance = '';
    public ?string $label = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public ?DateTime $dateActivated = null;
    public ?DateTime $dateDeactivated = null;
    public ?DateTime $dateLastSeen = null;
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
        return array_merge(parent::datetimeAttributes(), ['dateActivated', 'dateDeactivated', 'dateLastSeen']);
    }

    public function defineRules(): array
    {
        return [
            [['licenseId', 'instance'], 'required'],
            [['instance', 'label'], 'string', 'max' => 255],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_DEACTIVATED]],
            [['id', 'ip', 'userAgent', 'dateActivated', 'dateDeactivated', 'dateLastSeen', 'uid'], 'safe'],
        ];
    }

    /**
     * The form of an instance string that gets stored and compared.
     *
     * A URL loses its scheme, its `www.`, its trailing slash and its case; anything that is not a
     * URL is trimmed and lower-cased and otherwise left alone, because a machine fingerprint is
     * not ours to tidy.
     */
    public static function normalizeInstance(string $instance): string
    {
        $instance = trim($instance);

        if ($instance === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $instance)) {
            $host = parse_url($instance, PHP_URL_HOST) ?: $instance;
            $path = rtrim((string)parse_url($instance, PHP_URL_PATH), '/');
            $instance = preg_replace('/^www\./i', '', $host) . $path;
        }

        return mb_strtolower(rtrim($instance, '/'));
    }

    public function getIsActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getLabelOrInstance(): string
    {
        return $this->label !== null && trim($this->label) !== '' ? $this->label : $this->instance;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->id,
            'instance' => $this->instance,
            'label' => $this->label,
            'status' => $this->status,
            'dateActivated' => $this->dateActivated?->format(DATE_ATOM),
            'dateLastSeen' => $this->dateLastSeen?->format(DATE_ATOM),
        ];
    }
}
