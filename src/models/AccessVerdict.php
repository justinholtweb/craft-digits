<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use Craft;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;

/**
 * The answer to "may this person have this file, right now".
 *
 * One shape, produced by one service, and every caller reads it: the Twig helper deciding whether
 * to render a button, the controller minting a link, and the controller handing over the bytes.
 * That is the whole point — a template that shows a download button the delivery controller would
 * refuse is a bug report from a customer, and the only way to be sure it cannot happen is for both
 * to ask the same question and get the same object back.
 *
 * The reason is a code, not a sentence. Support looks it up in the log; the customer gets the
 * sentence {@see getMessage()} makes out of it, which deliberately says less — "this link has
 * expired" and not "licence 41 hit its limit of 3".
 */
class AccessVerdict
{
    public const REASON_OK = 'ok';
    public const REASON_NOT_FOUND = 'not-found';
    public const REASON_NO_LICENSE = 'no-license';
    public const REASON_EXPIRED = 'expired';
    public const REASON_REVOKED = 'revoked';
    public const REASON_DISABLED = 'disabled';
    public const REASON_PENDING = 'pending';
    public const REASON_LIMIT_REACHED = 'limit-reached';
    public const REASON_LOGIN_REQUIRED = 'login-required';
    public const REASON_GROUP_REQUIRED = 'group-required';
    public const REASON_UPDATE_GATED = 'update-gated';
    public const REASON_UNAVAILABLE = 'unavailable';
    public const REASON_TOKEN_EXPIRED = 'token-expired';
    public const REASON_TOKEN_SPENT = 'token-spent';
    public const REASON_TOKEN_IP = 'token-ip';
    public const REASON_TOKEN_UNKNOWN = 'token-unknown';
    public const REASON_DOWNLOAD_DISABLED = 'download-disabled';

    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason = self::REASON_OK,
        public readonly ?Download $download = null,
        public readonly ?License $license = null,
        public readonly ?Version $version = null,
        public readonly ?DownloadFile $file = null,
    ) {
    }

    public static function allow(
        ?Download $download = null,
        ?License $license = null,
        ?Version $version = null,
        ?DownloadFile $file = null,
    ): self {
        return new self(true, self::REASON_OK, $download, $license, $version, $file);
    }

    public static function deny(
        string $reason,
        ?Download $download = null,
        ?License $license = null,
        ?Version $version = null,
        ?DownloadFile $file = null,
    ): self {
        return new self(false, $reason, $download, $license, $version, $file);
    }

    /** A copy of this verdict carrying a version and file it did not have when it was made. */
    public function withTarget(?Version $version, ?DownloadFile $file): self
    {
        return new self($this->allowed, $this->reason, $this->download, $this->license, $version, $file);
    }

    /**
     * What the customer is told.
     *
     * Vague on purpose where being precise would help somebody guessing: an unknown token and a
     * spent token both say the link is no longer good, because the difference between them is
     * only interesting to whoever is trying them.
     */
    public function getMessage(): string
    {
        return match ($this->reason) {
            self::REASON_OK => Craft::t('digits', 'Ready to download.'),
            self::REASON_LOGIN_REQUIRED => Craft::t('digits', 'Sign in to download this file.'),
            self::REASON_GROUP_REQUIRED => Craft::t('digits', 'Your account does not have access to this file.'),
            self::REASON_NO_LICENSE => Craft::t('digits', 'You do not have access to this download.'),
            self::REASON_EXPIRED => Craft::t('digits', 'Your access to this download has expired.'),
            self::REASON_UPDATE_GATED => Craft::t('digits', 'This version was released after your access ended. Earlier versions are still available.'),
            self::REASON_REVOKED => Craft::t('digits', 'Access to this download has been withdrawn.'),
            self::REASON_DISABLED, self::REASON_DOWNLOAD_DISABLED => Craft::t('digits', 'This download is not available.'),
            self::REASON_PENDING => Craft::t('digits', 'This download will be ready once your order is paid.'),
            self::REASON_LIMIT_REACHED => Craft::t('digits', 'You have reached the download limit for this file.'),
            self::REASON_UNAVAILABLE => Craft::t('digits', 'This file is not available at the moment.'),
            self::REASON_TOKEN_IP => Craft::t('digits', 'This link was issued to a different device.'),
            default => Craft::t('digits', 'This link is no longer valid.'),
        };
    }

    /** What the control panel and the log say, which is the precise version. */
    public function getReasonLabel(): string
    {
        return match ($this->reason) {
            self::REASON_OK => Craft::t('digits', 'Allowed'),
            self::REASON_NOT_FOUND => Craft::t('digits', 'Download not found'),
            self::REASON_NO_LICENSE => Craft::t('digits', 'No licence'),
            self::REASON_EXPIRED => Craft::t('digits', 'Licence expired'),
            self::REASON_REVOKED => Craft::t('digits', 'Licence revoked'),
            self::REASON_DISABLED => Craft::t('digits', 'Licence disabled'),
            self::REASON_PENDING => Craft::t('digits', 'Licence pending payment'),
            self::REASON_LIMIT_REACHED => Craft::t('digits', 'Download limit reached'),
            self::REASON_LOGIN_REQUIRED => Craft::t('digits', 'Not signed in'),
            self::REASON_GROUP_REQUIRED => Craft::t('digits', 'Wrong user group'),
            self::REASON_UPDATE_GATED => Craft::t('digits', 'Version released after access ended'),
            self::REASON_UNAVAILABLE => Craft::t('digits', 'File missing'),
            self::REASON_TOKEN_EXPIRED => Craft::t('digits', 'Link expired'),
            self::REASON_TOKEN_SPENT => Craft::t('digits', 'Link already used'),
            self::REASON_TOKEN_IP => Craft::t('digits', 'Link used from another address'),
            self::REASON_TOKEN_UNKNOWN => Craft::t('digits', 'Unknown link'),
            self::REASON_DOWNLOAD_DISABLED => Craft::t('digits', 'Download disabled'),
            default => $this->reason,
        };
    }

    /**
     * Whether signing in might change the answer.
     *
     * The one distinction a template genuinely needs, because "sign in" and "you cannot have this"
     * are different buttons.
     */
    public function getIsAuthenticationProblem(): bool
    {
        return $this->reason === self::REASON_LOGIN_REQUIRED;
    }
}
