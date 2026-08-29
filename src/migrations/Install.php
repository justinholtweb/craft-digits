<?php

declare(strict_types=1);

namespace justinholtweb\digits\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\services\Vat;

/**
 * Digits' schema.
 *
 * Five shapes here are decisions rather than obvious defaults, and each one is the answer to a
 * question that a simpler schema gets wrong:
 *
 * - **Nothing has a foreign key into Commerce.** Digits installs and runs with Commerce absent,
 *   disabled, or arriving later, so `orderId` is a plain integer next to a copy of the order's
 *   reference. A real FK would make the install migration fail on a site without Commerce and
 *   would make uninstalling Commerce impossible on a site with it.
 *
 * - **A download link is a row, not a signature.** A stateless signed URL is smaller and needs no
 *   table — and cannot be taken back. Refunds have to revoke access, so the token that was
 *   emailed on Tuesday has to stop working on Thursday, and only a row can do that. The row holds
 *   the *hash* of the token, never the token, so a database that leaks does not hand out working
 *   links.
 *
 * - **A licence covers many downloads through a join table**, with no "primary" download column
 *   beside it. A bundle is one key that unlocks three products, and a schema with both a column
 *   and a table has two answers to "what does this key unlock".
 *
 * - **An invoice carries a snapshot of the order, not a join to it.** A VAT invoice is a legal
 *   document about a moment; the order it was written from keeps changing. Every figure and every
 *   address is copied in at issue time.
 *
 * - **The download log is append-only and separate from the counter.** `downloadCount` on the
 *   licence answers "has this key run out"; the log answers "who took what, from where, when" —
 *   which is the question asked months later, in an argument, and which cannot be reconstructed
 *   from a counter.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createCatalogueTables();
        $this->createLicenceTables();
        $this->createDeliveryTables();
        $this->createInvoicingTables();
        $this->addForeignKeys();
        $this->seedVatRates();

        return true;
    }

    public function safeDown(): bool
    {
        // Dropped children first: MySQL will not drop a table another table points at.
        $this->dropTableIfExists(Table::VATIDS);
        $this->dropTableIfExists(Table::VATRATES);
        $this->dropTableIfExists(Table::INVOICE_COUNTERS);
        $this->dropTableIfExists(Table::INVOICES);
        $this->dropTableIfExists(Table::NOTICES);
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::TOKENS);
        $this->dropTableIfExists(Table::ACTIVATIONS);
        $this->dropTableIfExists(Table::LICENSE_DOWNLOADS);
        $this->dropTableIfExists(Table::LICENSES);
        $this->dropTableIfExists(Table::FILES);
        $this->dropTableIfExists(Table::VERSIONS);
        $this->dropTableIfExists(Table::DOWNLOADS);

        $this->delete(CraftTable::ELEMENTS, ['type' => [
            Download::class,
            License::class,
        ]]);

        $this->delete(CraftTable::FIELDLAYOUTS, ['type' => [
            Download::class,
            License::class,
        ]]);

        return true;
    }

    private function createCatalogueTables(): void
    {
        $this->createTable(Table::DOWNLOADS, [
            'id' => $this->integer()->notNull(),
            // Matched against a Commerce line item's SKU when no relation field points here. The
            // relation is the intended bridge; this is the one that works on a site whose product
            // field layout somebody else owns.
            'sku' => $this->string(255),
            // How somebody who is *not* holding a licence is treated: 'licensed' (they are not
            // getting in), 'public', 'login', or 'groups'.
            'accessType' => $this->string(16)->notNull()->defaultValue('licensed'),
            'groupIds' => $this->text(),
            // Null on every one of these means "inherit the site default", which is what lets a
            // shop change its mind once instead of on every product. Zero means unlimited.
            'downloadLimit' => $this->smallInteger()->unsigned(),
            'accessDays' => $this->integer()->unsigned(),
            'linkTtl' => $this->integer()->unsigned(),
            'activationLimit' => $this->smallInteger()->unsigned(),
            'issuesLicense' => $this->boolean()->notNull()->defaultValue(true),
            // Whether a lapsed licence keeps the version it paid for but not the ones released
            // afterwards — the "one year of updates" model, and the reason `dateReleased` on a
            // version is load-bearing rather than decorative.
            'gateUpdatesOnExpiry' => $this->boolean()->notNull()->defaultValue(true),
            'notifyOnRelease' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, Table::DOWNLOADS, ['sku']);

        $this->createTable(Table::VERSIONS, [
            'id' => $this->primaryKey(),
            'downloadId' => $this->integer()->notNull(),
            'version' => $this->string(64)->notNull(),
            'releaseNotes' => $this->text(),
            // Exactly one live version per download is current. Enforced by the service rather
            // than by a partial index, which MySQL does not have.
            'isCurrent' => $this->boolean()->notNull()->defaultValue(false),
            'status' => $this->string(16)->notNull()->defaultValue('draft'),
            'dateReleased' => $this->dateTime(),
            'dateNotified' => $this->dateTime(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::VERSIONS, ['downloadId', 'version'], true);
        $this->createIndex(null, Table::VERSIONS, ['downloadId', 'isCurrent']);
        $this->createIndex(null, Table::VERSIONS, ['dateReleased']);

        $this->createTable(Table::FILES, [
            'id' => $this->primaryKey(),
            'versionId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'assetId' => $this->integer(),
            'url' => $this->string(500),
            'filename' => $this->string(255),
            'size' => $this->bigInteger()->unsigned(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::FILES, ['versionId']);
        $this->createIndex(null, Table::FILES, ['assetId']);
    }

    private function createLicenceTables(): void
    {
        $this->createTable(Table::LICENSES, [
            'id' => $this->integer()->notNull(),
            'licenseKey' => $this->string(128)->notNull(),
            'ownerId' => $this->integer(),
            // The identity when there is no account, which on a Commerce site is most of them.
            'email' => $this->string(255)->notNull(),
            'customerName' => $this->string(255),
            // Plain integers. See the note at the top of this file about Commerce.
            'orderId' => $this->integer(),
            'lineItemId' => $this->integer(),
            'orderReference' => $this->string(64),
            'status' => $this->string(16)->notNull()->defaultValue(License::STATUS_ACTIVE),
            'source' => $this->string(16)->notNull()->defaultValue(License::SOURCE_MANUAL),
            'issuedDate' => $this->dateTime()->notNull(),
            'expiryDate' => $this->dateTime(),
            'downloadLimit' => $this->smallInteger()->unsigned(),
            'downloadCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'activationLimit' => $this->smallInteger()->unsigned(),
            // Denormalised so the licences index does not run a count per row. `digits/licenses/recount`
            // puts it right if it ever drifts.
            'activationCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'revokedDate' => $this->dateTime(),
            'revokedReason' => $this->string(255),
            'notes' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        // The key is the credential. Unique across the install, and the index the activation API
        // hits on every check.
        $this->createIndex(null, Table::LICENSES, ['licenseKey'], true);
        $this->createIndex(null, Table::LICENSES, ['email']);
        $this->createIndex(null, Table::LICENSES, ['orderId']);
        $this->createIndex(null, Table::LICENSES, ['status', 'expiryDate']);

        $this->createTable(Table::LICENSE_DOWNLOADS, [
            'id' => $this->primaryKey(),
            'licenseId' => $this->integer()->notNull(),
            'downloadId' => $this->integer()->notNull(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::LICENSE_DOWNLOADS, ['licenseId', 'downloadId'], true);
        $this->createIndex(null, Table::LICENSE_DOWNLOADS, ['downloadId']);

        $this->createTable(Table::ACTIVATIONS, [
            'id' => $this->primaryKey(),
            'licenseId' => $this->integer()->notNull(),
            // Whatever the customer's software calls itself: a site URL, a machine fingerprint, a
            // container id. Digits does not care what it is, only that the same one twice is the
            // same seat.
            'instance' => $this->string(255)->notNull(),
            'label' => $this->string(255),
            'status' => $this->string(16)->notNull()->defaultValue('active'),
            'ip' => $this->string(45),
            'userAgent' => $this->string(255),
            'dateActivated' => $this->dateTime()->notNull(),
            'dateDeactivated' => $this->dateTime(),
            'dateLastSeen' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row per seat, reactivated in place. A deactivate-then-reactivate that inserted a
        // second row would count the same machine twice against the limit.
        $this->createIndex(null, Table::ACTIVATIONS, ['licenseId', 'instance'], true);
    }

    private function createDeliveryTables(): void
    {
        $this->createTable(Table::TOKENS, [
            'id' => $this->primaryKey(),
            // sha256 of the token, never the token. A leaked backup is then a list of hashes.
            'tokenHash' => $this->char(64)->notNull(),
            'type' => $this->string(16)->notNull()->defaultValue('download'),
            'licenseId' => $this->integer(),
            'downloadId' => $this->integer(),
            'versionId' => $this->integer(),
            'fileId' => $this->integer(),
            'userId' => $this->integer(),
            'email' => $this->string(255),
            'orderId' => $this->integer(),
            'maxUses' => $this->smallInteger()->unsigned(),
            'uses' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'ip' => $this->string(45),
            'expiryDate' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::TOKENS, ['tokenHash'], true);
        $this->createIndex(null, Table::TOKENS, ['expiryDate']);
        $this->createIndex(null, Table::TOKENS, ['licenseId']);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'event' => $this->string(24)->notNull(),
            'licenseId' => $this->integer(),
            'downloadId' => $this->integer(),
            'versionId' => $this->integer(),
            'fileId' => $this->integer(),
            'tokenId' => $this->integer(),
            'userId' => $this->integer(),
            'email' => $this->string(255),
            // A machine-readable deny reason — 'expired', 'limit-reached', 'revoked' — so the
            // support answer to "why can't they download it" is a lookup rather than a guess.
            'reason' => $this->string(64),
            'detail' => $this->string(500),
            'ip' => $this->string(45),
            'userAgent' => $this->string(500),
            'bytes' => $this->bigInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::LOG, ['event', 'dateCreated']);
        $this->createIndex(null, Table::LOG, ['licenseId']);
        $this->createIndex(null, Table::LOG, ['downloadId']);

        $this->createTable(Table::NOTICES, [
            'id' => $this->primaryKey(),
            'versionId' => $this->integer()->notNull(),
            'licenseId' => $this->integer()->notNull(),
            'email' => $this->string(255)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('sent'),
            'detail' => $this->string(500),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The reason a re-run of the notifier is safe: the second attempt collides here rather
        // than mailing everybody a second time.
        $this->createIndex(null, Table::NOTICES, ['versionId', 'licenseId'], true);
    }

    private function createInvoicingTables(): void
    {
        $this->createTable(Table::INVOICES, [
            'id' => $this->primaryKey(),
            'number' => $this->string(64)->notNull(),
            'series' => $this->string(32)->notNull()->defaultValue('default'),
            'orderId' => $this->integer(),
            'orderReference' => $this->string(64),
            'userId' => $this->integer(),
            'customerEmail' => $this->string(255),
            'customerName' => $this->string(255),
            'sellerCountry' => $this->string(2),
            'sellerVatId' => $this->string(32),
            'buyerCountry' => $this->string(2),
            'buyerVatId' => $this->string(32),
            'buyerVatIdValid' => $this->boolean(),
            'buyerType' => $this->string(8)->notNull()->defaultValue('b2c'),
            // 'domestic', 'oss', 'reverse-charge', 'outside-scope' — the single word that decides
            // both the figure and the sentence printed under it.
            'treatment' => $this->string(24)->notNull()->defaultValue('domestic'),
            'vatRate' => $this->decimal(6, 4)->notNull()->defaultValue(0),
            'currency' => $this->string(3),
            'netAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'vatAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'grossAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            // What Commerce actually charged, kept beside what the treatment says it should have
            // been. Digits never rewrites a charge — it reports the gap.
            'chargedTaxAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'hasDiscrepancy' => $this->boolean()->notNull()->defaultValue(false),
            'evidence' => $this->text(),
            'snapshot' => $this->mediumText(),
            'status' => $this->string(16)->notNull()->defaultValue('issued'),
            'creditOfId' => $this->integer(),
            'dateIssued' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::INVOICES, ['number'], true);
        $this->createIndex(null, Table::INVOICES, ['orderId']);
        $this->createIndex(null, Table::INVOICES, ['dateIssued']);
        $this->createIndex(null, Table::INVOICES, ['buyerCountry', 'dateIssued']);

        $this->createTable(Table::INVOICE_COUNTERS, [
            'id' => $this->primaryKey(),
            'series' => $this->string(32)->notNull(),
            // '', '2026' or '2026-08', depending on how often the series restarts.
            'periodKey' => $this->string(16)->notNull()->defaultValue(''),
            'counter' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::INVOICE_COUNTERS, ['series', 'periodKey'], true);

        $this->createTable(Table::VATRATES, [
            'id' => $this->primaryKey(),
            'countryCode' => $this->string(2)->notNull(),
            'name' => $this->string(64),
            'rate' => $this->decimal(6, 4)->notNull()->defaultValue(0),
            'kind' => $this->string(16)->notNull()->defaultValue('standard'),
            // A rate change is a date, not a deploy: the invoice for March has to keep using
            // March's rate after April's takes effect.
            'effectiveFrom' => $this->dateTime(),
            'effectiveTo' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::VATRATES, ['countryCode', 'kind', 'effectiveFrom']);

        $this->createTable(Table::VATIDS, [
            'id' => $this->primaryKey(),
            'vatId' => $this->string(32)->notNull(),
            'countryCode' => $this->string(2)->notNull(),
            'valid' => $this->boolean(),
            'name' => $this->string(255),
            'address' => $this->string(500),
            // 'vies' when Brussels answered, 'format' when only the checksum was checked, and
            // 'manual' when a human decided. Printed on the invoice audit trail, because the
            // three are not the same evidence.
            'source' => $this->string(16)->notNull()->defaultValue('format'),
            'consultationNumber' => $this->string(64),
            'dateChecked' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::VATIDS, ['vatId'], true);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::DOWNLOADS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LICENSES, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LICENSES, ['ownerId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::VERSIONS, ['downloadId'], Table::DOWNLOADS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::FILES, ['versionId'], Table::VERSIONS, ['id'], 'CASCADE', null);
        // The asset going away must not take the file row with it — an empty row is a fixable
        // mistake, a vanished one is a mystery.
        $this->addForeignKey(null, Table::FILES, ['assetId'], CraftTable::ASSETS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::LICENSE_DOWNLOADS, ['licenseId'], Table::LICENSES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LICENSE_DOWNLOADS, ['downloadId'], Table::DOWNLOADS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ACTIVATIONS, ['licenseId'], Table::LICENSES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::TOKENS, ['licenseId'], Table::LICENSES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TOKENS, ['downloadId'], Table::DOWNLOADS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TOKENS, ['versionId'], Table::VERSIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TOKENS, ['fileId'], Table::FILES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TOKENS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);

        // The log outlives everything it points at. That is the entire point of a log.
        $this->addForeignKey(null, Table::LOG, ['licenseId'], Table::LICENSES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::LOG, ['downloadId'], Table::DOWNLOADS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::LOG, ['versionId'], Table::VERSIONS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::LOG, ['fileId'], Table::FILES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::LOG, ['tokenId'], Table::TOKENS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::LOG, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::NOTICES, ['versionId'], Table::VERSIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::NOTICES, ['licenseId'], Table::LICENSES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::INVOICES, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::INVOICES, ['creditOfId'], Table::INVOICES, ['id'], 'SET NULL', null);
    }

    /**
     * The 27 EU standard rates, as a starting point.
     *
     * Seeded rather than hard-coded so that a rate change is an afternoon in the control panel
     * instead of a plugin release — and dated, so the invoice raised before the change keeps the
     * rate it was raised under. They are checked against the Commission's published table at
     * release; a shop that files its own returns should still confirm them before its first
     * quarter, which is why the VAT screen shows the date they were seeded.
     */
    private function seedVatRates(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $from = (new \DateTime('2026-01-01', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stamp = $now->format('Y-m-d H:i:s');
        $rows = [];

        foreach (Vat::SEED_RATES as $country => $rate) {
            $rows[] = [
                $country,
                Vat::countryName($country),
                $rate,
                'standard',
                $from,
                null,
                $stamp,
                $stamp,
                \craft\helpers\StringHelper::UUID(),
            ];
        }

        $this->batchInsert(Table::VATRATES, [
            'countryCode',
            'name',
            'rate',
            'kind',
            'effectiveFrom',
            'effectiveTo',
            'dateCreated',
            'dateUpdated',
            'uid',
        ], $rows);
    }
}
