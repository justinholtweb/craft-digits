<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use Craft;
use justinholtweb\digits\elements\License;

/**
 * What came back from an activation attempt.
 *
 * A shape rather than a boolean, because the activation API's whole job is to tell a piece of
 * software running on somebody else's machine *why* it was turned away — "the key is wrong",
 * "you have used all your seats" and "the licence lapsed in March" lead to three different
 * dialogues, and a false leads to one bad one.
 */
class ActivationResult
{
    public const OK = 'ok';
    public const UNKNOWN_KEY = 'unknown-key';
    public const NOT_ACTIVE = 'not-active';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';
    public const SEAT_LIMIT = 'seat-limit';
    public const NOT_ACTIVATED = 'not-activated';
    public const NO_INSTANCE = 'no-instance';
    public const DISABLED = 'disabled';

    public function __construct(
        public readonly bool $success,
        public readonly string $code = self::OK,
        public readonly ?License $license = null,
        public readonly ?Activation $activation = null,
    ) {
    }

    public static function ok(License $license, ?Activation $activation = null): self
    {
        return new self(true, self::OK, $license, $activation);
    }

    public static function fail(string $code, ?License $license = null): self
    {
        return new self(false, $code, $license);
    }

    public function getMessage(): string
    {
        return match ($this->code) {
            self::OK => Craft::t('digits', 'Activated.'),
            self::UNKNOWN_KEY => Craft::t('digits', 'That licence key was not recognised.'),
            self::EXPIRED => Craft::t('digits', 'That licence expired on {date}.', [
                'date' => $this->license?->expiryDate !== null
                    ? Craft::$app->getFormatter()->asDate($this->license->expiryDate, 'medium')
                    : '—',
            ]),
            self::REVOKED => Craft::t('digits', 'That licence has been withdrawn.'),
            self::SEAT_LIMIT => Craft::t('digits', 'That licence is already in use on all {count} of its activations. Deactivate one first.', [
                'count' => $this->license?->getEffectiveActivationLimit() ?? 0,
            ]),
            self::NOT_ACTIVATED => Craft::t('digits', 'That licence is not activated here.'),
            self::NO_INSTANCE => Craft::t('digits', 'No site or machine was given to activate.'),
            self::DISABLED => Craft::t('digits', 'Licence activation is switched off.'),
            default => Craft::t('digits', 'That licence is not active.'),
        };
    }

    /** The JSON a customer's software gets back. */
    public function toArray(): array
    {
        return array_filter([
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->getMessage(),
            'license' => $this->license !== null ? [
                'key' => $this->license->licenseKey,
                'status' => $this->license->getStatus(),
                'expiryDate' => $this->license->expiryDate?->format(DATE_ATOM),
                'activationLimit' => $this->license->getEffectiveActivationLimit(),
                'activationCount' => $this->license->activationCount,
                'downloads' => array_map(
                    static fn($download) => ['id' => $download->id, 'title' => $download->title],
                    $this->license->getDownloads(),
                ),
            ] : null,
            'activation' => $this->activation?->toArray(),
        ], static fn($value) => $value !== null);
    }
}
