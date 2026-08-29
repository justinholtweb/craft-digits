<?php

declare(strict_types=1);

namespace justinholtweb\digits\db;

/**
 * Digits' table names, in one place.
 *
 * Every query and every migration reads its table name from here, so a query written against
 * `digits_licenses` and the migration that created it cannot drift apart in a typo that only
 * shows up on the one database driver nobody tested.
 */
abstract class Table
{
    public const DOWNLOADS = '{{%digits_downloads}}';
    public const VERSIONS = '{{%digits_versions}}';
    public const FILES = '{{%digits_files}}';
    public const LICENSES = '{{%digits_licenses}}';
    public const LICENSE_DOWNLOADS = '{{%digits_license_downloads}}';
    public const ACTIVATIONS = '{{%digits_activations}}';
    public const TOKENS = '{{%digits_tokens}}';
    public const LOG = '{{%digits_log}}';
    public const NOTICES = '{{%digits_notices}}';
    public const INVOICES = '{{%digits_invoices}}';
    public const INVOICE_COUNTERS = '{{%digits_invoicecounters}}';
    public const VATRATES = '{{%digits_vatrates}}';
    public const VATIDS = '{{%digits_vatids}}';
}
