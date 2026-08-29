<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * The rule the split follows: **Lite is a working download shop, not a demo.** Selling files,
 * protecting them behind expiring links, capping how many times each buyer may take them,
 * versioning them, and revoking them when the money goes back are all in Lite, uncapped. There is
 * deliberately **no limit on the number of downloads or the number of buyers** — charging for the
 * thing that grows with a shop's success is a tax on the customer's good year.
 *
 * Pro is the *software vendor's* half: licence keys that machines check, activation seats, the
 * portal a customer logs into to manage them, the release notices that go out with a new build,
 * and the VAT paperwork that comes with selling to Europe.
 */
class Edition
{
    /**
     * Licence keys, the activation API and everything that hangs off them.
     *
     * Lite still issues a licence row per purchase — it has to, because that row *is* the
     * entitlement that makes a link work. What Lite does not do is give it a key anybody can type
     * into a piece of software, or count seats against it.
     */
    public static function allowsLicenseKeys(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsActivations(bool $isPro): bool
    {
        return $isPro;
    }

    /** The front-end portal where a customer manages their own licences, seats and invoices. */
    public static function allowsPortal(bool $isPro): bool
    {
        return $isPro;
    }

    /** Emailing every licensee when a new version is released. */
    public static function allowsUpdateNotices(bool $isPro): bool
    {
        return $isPro;
    }

    /** Withholding versions released after a licence lapsed — the "one year of updates" model. */
    public static function allowsUpdateGating(bool $isPro): bool
    {
        return $isPro;
    }

    /** VAT invoices, VAT ID validation and the OSS return. */
    public static function allowsInvoicing(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Versions per download that Lite may hold.
     *
     * Two, which is enough for "the file" and "the file, corrected" — the reason a shop wants
     * versions at all. A release history is a software vendor's need, and software vendors are
     * who Pro is for.
     */
    public const LITE_MAX_VERSIONS = 2;

    public static function maxVersions(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_VERSIONS;
    }
}
