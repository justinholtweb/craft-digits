<?php
/**
 * Digits integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-digits/tests/integration/checks.php
 *
 * Covers what unit fixtures cannot: real element saves through Craft, the access verdict against
 * the actual database, the conditional updates that enforce limits, and the edition boundary.
 * Idempotent and self-cleaning — every run creates its own downloads, versions and licences under a
 * unique suffix and deletes them at the end.
 *
 * Nothing here sends an email or reaches VIES: notifications are switched off for the duration and
 * VAT ID validation is left in its offline mode, because a suite that depends on the Commission's
 * service being up fails for reasons that are not bugs.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\helpers\Db;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\AccessVerdict;
use justinholtweb\digits\models\Activation;
use justinholtweb\digits\models\ActivationResult;
use justinholtweb\digits\models\DownloadFile;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\Invoice;
use justinholtweb\digits\models\LinkToken;
use justinholtweb\digits\models\Version;
use justinholtweb\digits\Plugin;
use justinholtweb\digits\services\Log;
use justinholtweb\digits\services\Vat;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$originalEdition = $plugin->edition;

/**
 * Switch the edition in this process only.
 *
 * Not `switchEdition()`: that writes to project config, and a script that writes to project config
 * more than once in a run hits `StaleResourceException` on the second write — and the change is
 * undone the next time `project.yaml` is applied anyway. The edition is a public property, every
 * check in the plugin goes through `isPro()`, and this is restored in the `finally` block.
 */
$setEdition = static function(string $edition) use ($plugin): void {
    $plugin->edition = $edition;
};

$setEdition(Plugin::EDITION_PRO);

$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();

// The suite must not mail anybody, and must not depend on a mail server being up.
$settings->notifyOnIssue = false;
$settings->notifyOnRelease = false;
$settings->validateVatIds = false;
$settings->enablePortal = true;
$settings->enableActivationApi = true;
$settings->downloadLimit = 0;
$settings->accessDays = 0;
$settings->activationLimit = 1;
$settings->linkTtl = 60;
$settings->bindLinksToIp = false;

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$created = ['downloads' => [], 'licenses' => [], 'users' => [], 'groups' => [], 'invoices' => [], 'rates' => []];

/** Create a download, remembering it for cleanup. */
$makeDownload = function(array $config = []) use (&$created, $suffix): Download {
    $download = new Download();
    $download->title = ($config['title'] ?? 'Check download') . " $suffix";
    $download->sku = $config['sku'] ?? null;
    $download->accessType = $config['accessType'] ?? Download::ACCESS_LICENSED;
    $download->enabled = $config['enabled'] ?? true;
    $download->downloadLimit = $config['downloadLimit'] ?? null;
    $download->accessDays = $config['accessDays'] ?? null;
    $download->activationLimit = $config['activationLimit'] ?? null;
    $download->gateUpdatesOnExpiry = $config['gateUpdatesOnExpiry'] ?? true;
    $download->issuesLicense = $config['issuesLicense'] ?? true;

    if (isset($config['groupIds'])) {
        $download->setGroupIds($config['groupIds']);
    }

    if (!Craft::$app->getElements()->saveElement($download)) {
        throw new RuntimeException('Could not save a fixture download: ' . json_encode($download->getErrors()));
    }

    $created['downloads'][] = (int)$download->id;

    return $download;
};

/** Create a live version with one external file, so nothing depends on a volume. */
$makeVersion = function(Download $download, string $number, array $config = []) use ($plugin): Version {
    $version = new Version([
        'downloadId' => (int)$download->id,
        'version' => $number,
        'status' => $config['status'] ?? Version::STATUS_LIVE,
        'dateReleased' => $config['dateReleased'] ?? new DateTime(),
    ]);

    $version->setFiles([
        new DownloadFile([
            'name' => 'Bundle',
            'url' => 'https://example.com/' . $download->id . '-' . $number . '.zip',
            'size' => 1024,
        ]),
    ]);

    if (!$plugin->versions->saveVersion($version)) {
        throw new RuntimeException('Could not save a fixture version: ' . json_encode($version->getErrors()));
    }

    if ($version->getIsLive() && ($config['makeCurrent'] ?? true)) {
        $plugin->versions->makeCurrent($version);
    }

    return $version;
};

$makeLicense = function(array $config) use ($plugin, &$created, $suffix): License {
    $license = $plugin->licenses->issue(array_merge([
        'email' => "checks-$suffix@example.com",
        'source' => License::SOURCE_CONSOLE,
    ], $config));

    if ($license === null) {
        throw new RuntimeException('Could not issue a fixture licence.');
    }

    $created['licenses'][] = (int)$license->id;

    return $license;
};

try {
    // ---------------------------------------------------------------------------------------------
    section('Install');

    check('every table the plugin declares exists', function() {
        $tables = Craft::$app->getDb()->getSchema()->getTableNames();
        $missing = [];

        foreach ((new ReflectionClass(Table::class))->getConstants() as $name) {
            $bare = trim(str_replace(['{{%', '}}'], '', $name));

            if (!in_array($bare, $tables, true)) {
                $missing[] = $bare;
            }
        }

        return $missing === [] ?: 'missing: ' . implode(', ', $missing);
    });

    check('the 27 EU standard rates were seeded', function() use ($plugin) {
        $countries = [];

        foreach ($plugin->vat->getAllRates() as $rate) {
            $countries[strtoupper($rate->countryCode)] = true;
        }

        $missing = array_diff(Vat::EU_COUNTRIES, array_keys($countries));

        return $missing === [] ?: 'missing: ' . implode(', ', $missing);
    });

    check('nothing has a foreign key into a Commerce table', function() {
        // The install has to work on a site with no Commerce at all, which is only true while no
        // Digits table points at one.
        $schema = Craft::$app->getDb()->getSchema();
        $offenders = [];

        foreach ($schema->getTableNames() as $table) {
            if (!str_starts_with($table, 'digits_')) {
                continue;
            }

            foreach ($schema->getTableSchema($table)->foreignKeys as $fk) {
                $target = reset($fk);

                if (is_string($target) && str_contains($target, 'commerce')) {
                    $offenders[] = "$table → $target";
                }
            }
        }

        return $offenders === [] ?: implode(', ', $offenders);
    });

    // ---------------------------------------------------------------------------------------------
    section('Downloads');

    $licensed = $makeDownload(['title' => 'Licensed toolkit', 'sku' => "TOOLKIT-$suffix"]);

    check('a download saves and reads back its own columns', function() use ($plugin, $licensed) {
        $fresh = $plugin->downloads->getDownloadById((int)$licensed->id);

        return $fresh !== null
            && $fresh->sku === $licensed->sku
            && $fresh->accessType === Download::ACCESS_LICENSED
            && $fresh->issuesLicense === true;
    });

    check('a download is found by SKU', function() use ($plugin, $licensed) {
        $found = $plugin->downloads->getDownloadBySku((string)$licensed->sku);

        return $found !== null && (int)$found->id === (int)$licensed->id;
    });

    check('an empty limit inherits the site default, and zero does not', function() use ($plugin, $makeDownload) {
        $plugin->getSettings()->downloadLimit = 7;

        $inherits = $makeDownload(['title' => 'Inherits']);
        $explicit = $makeDownload(['title' => 'Explicit', 'downloadLimit' => 0]);

        $result = $inherits->getEffectiveDownloadLimit() === 7 && $explicit->getEffectiveDownloadLimit() === 0;

        $plugin->getSettings()->downloadLimit = 0;

        return $result ?: 'inherit=' . $inherits->getEffectiveDownloadLimit() . ' explicit=' . $explicit->getEffectiveDownloadLimit();
    });

    check('group ids survive the round trip through the JSON column', function() use ($plugin, $makeDownload) {
        $download = $makeDownload(['title' => 'Grouped', 'accessType' => Download::ACCESS_GROUPS, 'groupIds' => [11, 22]]);
        $fresh = $plugin->downloads->getDownloadById((int)$download->id);

        return $fresh !== null && $fresh->getGroupIds() === [11, 22]
            ?: 'got ' . json_encode($fresh?->getGroupIds());
    });

    check('a groups download with no groups will not save', function() use ($suffix) {
        $download = new Download();
        $download->title = "No groups $suffix";
        $download->accessType = Download::ACCESS_GROUPS;

        return !Craft::$app->getElements()->saveElement($download)
            && $download->hasErrors('groupIds');
    });

    // ---------------------------------------------------------------------------------------------
    section('Versions');

    $v1 = $makeVersion($licensed, '1.0.0', ['dateReleased' => new DateTime('-90 days')]);
    $v2 = $makeVersion($licensed, '2.0.0', ['dateReleased' => new DateTime('-1 day')]);

    check('the newest release is the current one, and it is the only one', function() use ($plugin, $licensed, $v2) {
        $current = (new craft\db\Query())
            ->select(['id'])
            ->from([Table::VERSIONS])
            ->where(['downloadId' => $licensed->id, 'isCurrent' => true])
            ->column();

        return count($current) === 1 && (int)$current[0] === (int)$v2->id
            ?: 'current ids: ' . json_encode($current);
    });

    check('versions come back newest release first', function() use ($plugin, $licensed) {
        $versions = $plugin->versions->getVersionsForDownload((int)$licensed->id, false);

        return count($versions) === 2 && $versions[0]->version === '2.0.0';
    });

    check('a version sorts by number, not by string', function() {
        $nine = new Version(['version' => '1.9.0']);
        $ten = new Version(['version' => '1.10.0']);

        return strcmp($nine->getSortKey(), $ten->getSortKey()) < 0;
    });

    check('a released version with no files is refused', function() use ($plugin, $licensed) {
        $version = new Version([
            'downloadId' => (int)$licensed->id,
            'version' => 'empty-1',
            'status' => Version::STATUS_LIVE,
        ]);

        return !$plugin->versions->saveVersion($version) && $version->hasErrors('files');
    });

    check('two versions of one download cannot share a number', function() use ($plugin, $licensed) {
        $version = new Version([
            'downloadId' => (int)$licensed->id,
            'version' => '1.0.0',
            'status' => Version::STATUS_DRAFT,
        ]);

        return !$plugin->versions->saveVersion($version) && $version->hasErrors('version');
    });

    check('saving files replaces the set rather than appending to it', function() use ($plugin, $licensed, $v1) {
        $version = $plugin->versions->getVersionById((int)$v1->id);
        $version->setFiles([
            new DownloadFile(['name' => 'Only file', 'url' => 'https://example.com/only.zip']),
        ]);

        $plugin->versions->saveVersion($version);
        $plugin->versions->clearCaches();

        $files = $plugin->versions->getFilesForVersion((int)$v1->id);

        return count($files) === 1 && $files[0]->name === 'Only file'
            ?: count($files) . ' files';
    });

    check('a file has to be an asset or a URL, never both', function() {
        $file = new DownloadFile([
            'name' => 'Both',
            'assetId' => 1,
            'url' => 'https://example.com/x.zip',
        ]);

        return !$file->validate() && $file->hasErrors('assetId');
    });

    check('deleting the current version hands current to what is left', function() use ($plugin, $makeDownload, $makeVersion) {
        $download = $makeDownload(['title' => 'Handover']);
        $old = $makeVersion($download, '1.0.0', ['dateReleased' => new DateTime('-10 days')]);
        $new = $makeVersion($download, '1.1.0');

        $plugin->versions->deleteVersionById((int)$new->id);
        $plugin->versions->clearCaches();

        $current = $plugin->versions->getCurrentVersion((int)$download->id);

        return $current !== null && (int)$current->id === (int)$old->id
            ?: 'current is ' . ($current?->version ?? 'none');
    });

    // ---------------------------------------------------------------------------------------------
    section('Keys');

    check('a key follows the configured format', function() use ($plugin) {
        $key = $plugin->keys->format('XXXX-XXXX', 'ABCDEF');

        return preg_match('/^[A-F]{4}-[A-F]{4}$/', $key) === 1 ?: "got $key";
    });

    check('the default alphabet leaves out the characters people mis-read', function() use ($plugin) {
        $alphabet = $plugin->getSettings()->keyAlphabet;

        foreach (['I', 'O', '0', '1'] as $char) {
            if (str_contains($alphabet, $char)) {
                return "alphabet contains $char";
            }
        }

        return true;
    });

    check('normalising folds the characters a human types instead', function() use ($plugin) {
        $keys = $plugin->keys;

        return $keys->normalize('abcd-efgh') === $keys->normalize('ABCDEFGH')
            && $keys->normalize('IO1') === $keys->normalize('101')
            && $keys->normalize('L') === '1';
    });

    check('a generated key is not already in use', function() use ($plugin) {
        $key = $plugin->keys->generate();

        return !$plugin->keys->exists($key);
    });

    // ---------------------------------------------------------------------------------------------
    section('Licences');

    $license = $makeLicense(['downloadIds' => [(int)$licensed->id]]);

    check('issuing produces a key and links the download', function() use ($plugin, $license, $licensed) {
        $fresh = $plugin->licenses->getLicenseById((int)$license->id);

        return $fresh !== null
            && $fresh->licenseKey !== null
            && $fresh->coversDownload((int)$licensed->id)
            ?: 'key=' . var_export($fresh?->licenseKey, true);
    });

    check('a licence is found by its key, and by a mistyped one', function() use ($plugin, $license) {
        $exact = $plugin->licenses->getLicenseByKey((string)$license->licenseKey);
        $sloppy = $plugin->licenses->getLicenseByKey(strtolower(str_replace('-', ' ', (string)$license->licenseKey)));

        return $exact !== null && $sloppy !== null && (int)$sloppy->id === (int)$license->id;
    });

    check('a licence with no downloads will not save', function() use ($suffix) {
        $license = new License();
        $license->email = "nothing-$suffix@example.com";

        return !Craft::$app->getElements()->saveElement($license)
            && $license->hasErrors('downloadIds');
    });

    check('the default expiry takes the longest window of what it covers', function() use ($plugin, $makeDownload) {
        $short = $makeDownload(['title' => 'Short', 'accessDays' => 30]);
        $long = $makeDownload(['title' => 'Long', 'accessDays' => 365]);

        $expiry = $plugin->licenses->defaultExpiry([(int)$short->id, (int)$long->id], new DateTime());
        $days = (int)round(($expiry->getTimestamp() - time()) / 86400);

        return $days === 365 ?: "got $days days";
    });

    check('one perpetual part makes the whole licence perpetual', function() use ($plugin, $makeDownload) {
        $dated = $makeDownload(['title' => 'Dated', 'accessDays' => 30]);
        $forever = $makeDownload(['title' => 'Forever', 'accessDays' => 0]);

        return $plugin->licenses->defaultExpiry([(int)$dated->id, (int)$forever->id]) === null;
    });

    check('the strictest download limit of a bundle wins', function() use ($plugin, $makeDownload, $makeLicense) {
        $tight = $makeDownload(['title' => 'Tight', 'downloadLimit' => 2]);
        $loose = $makeDownload(['title' => 'Loose', 'downloadLimit' => 9]);

        $bundle = $makeLicense(['downloadIds' => [(int)$tight->id, (int)$loose->id]]);

        return $bundle->getEffectiveDownloadLimit() === 2 ?: 'got ' . $bundle->getEffectiveDownloadLimit();
    });

    check('spending decrements the allowance and refuses when it is gone', function() use ($plugin, $makeDownload, $makeLicense) {
        $capped = $makeDownload(['title' => 'Capped', 'downloadLimit' => 2]);
        $license = $makeLicense(['downloadIds' => [(int)$capped->id]]);

        $first = $plugin->licenses->spend($license);
        $second = $plugin->licenses->spend($license);
        $third = $plugin->licenses->spend($license);

        return $first && $second && !$third && $license->downloadCount === 2
            ?: sprintf('%s/%s/%s count=%d', var_export($first, true), var_export($second, true), var_export($third, true), $license->downloadCount);
    });

    check('the limit is enforced by the database, not by the model in memory', function() use ($plugin, $makeDownload, $makeLicense) {
        // Two separate instances of the same licence, which is what two simultaneous requests are.
        $capped = $makeDownload(['title' => 'Race', 'downloadLimit' => 1]);
        $license = $makeLicense(['downloadIds' => [(int)$capped->id]]);

        $a = $plugin->licenses->getLicenseById((int)$license->id);
        $b = $plugin->licenses->getLicenseById((int)$license->id);

        $first = $plugin->licenses->spend($a);
        $second = $plugin->licenses->spend($b);

        return $first && !$second ?: 'both spends succeeded';
    });

    check('resetting the count puts it back to zero', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Reset', 'downloadLimit' => 1]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $plugin->licenses->spend($license);
        $plugin->licenses->resetDownloadCount($license);

        return $license->downloadCount === 0 && $plugin->licenses->spend($license);
    });

    check('extending a lapsed licence starts from today, not from when it lapsed', function() use ($plugin, $licensed, $makeLicense) {
        $license = $makeLicense([
            'downloadIds' => [(int)$licensed->id],
            'expiryDate' => new DateTime('-100 days'),
        ]);

        $plugin->licenses->extend($license, 10);
        $days = (int)round(($license->expiryDate->getTimestamp() - time()) / 86400);

        return $days === 10 ?: "got $days days";
    });

    check('the expiry sweep stamps the column without changing any decision', function() use ($plugin, $licensed, $makeLicense) {
        $license = $makeLicense([
            'downloadIds' => [(int)$licensed->id],
            'expiryDate' => new DateTime('-1 day'),
        ]);

        // Already reported as expired, before anything has swept.
        $before = $license->getStatus();

        $plugin->licenses->expireLapsed();

        $status = (new craft\db\Query())
            ->select(['status'])
            ->from([Table::LICENSES])
            ->where(['id' => $license->id])
            ->scalar();

        return $before === License::STATUS_EXPIRED && $status === License::STATUS_EXPIRED
            ?: "before=$before column=$status";
    });

    check('a re-save that never touched the downloads does not unlink them', function() use ($plugin, $license, $licensed) {
        $fresh = $plugin->licenses->getLicenseById((int)$license->id);
        $fresh->notes = 'touched';

        Craft::$app->getElements()->saveElement($fresh, false);

        $again = $plugin->licenses->getLicenseById((int)$license->id);

        return $again->coversDownload((int)$licensed->id) ?: 'the downloads were wiped';
    });

    // ---------------------------------------------------------------------------------------------
    section('Access');

    check('a public download is allowed with no licence and no session', function() use ($plugin, $makeDownload) {
        $public = $makeDownload(['title' => 'Public', 'accessType' => Download::ACCESS_PUBLIC]);

        return $plugin->access->check($public)->allowed;
    });

    check('a sign-in download tells an anonymous visitor to sign in', function() use ($plugin, $makeDownload) {
        $gated = $makeDownload(['title' => 'Gated', 'accessType' => Download::ACCESS_LOGIN]);
        $verdict = $plugin->access->check($gated);

        return !$verdict->allowed
            && $verdict->reason === AccessVerdict::REASON_LOGIN_REQUIRED
            && $verdict->getIsAuthenticationProblem();
    });

    check('a disabled download is refused even to a live licence', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Withdrawn', 'enabled' => false]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $verdict = $plugin->access->check($download, $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_DOWNLOAD_DISABLED
            ?: 'reason ' . $verdict->reason;
    });

    check('a licence that does not cover the download is not a licence for it', function() use ($plugin, $makeDownload, $makeLicense) {
        $mine = $makeDownload(['title' => 'Mine']);
        $theirs = $makeDownload(['title' => 'Theirs']);
        $license = $makeLicense(['downloadIds' => [(int)$mine->id]]);

        $verdict = $plugin->access->check($theirs, $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_NO_LICENSE;
    });

    check('a revoked licence is refused', function() use ($plugin, $licensed, $makeLicense) {
        $license = $makeLicense(['downloadIds' => [(int)$licensed->id]]);
        $plugin->licenses->revoke($license, 'checks');

        $verdict = $plugin->access->check($licensed, $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_REVOKED;
    });

    check('a restored licence works again', function() use ($plugin, $licensed, $makeLicense) {
        $license = $makeLicense(['downloadIds' => [(int)$licensed->id]]);
        $plugin->licenses->revoke($license, 'checks');
        $plugin->licenses->restore($license);

        return $plugin->access->check($licensed, $license)->allowed;
    });

    check('a licence over its limit is refused before any bytes move', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Spent', 'downloadLimit' => 1]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $plugin->licenses->spend($license);

        $verdict = $plugin->access->check($download, $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_LIMIT_REACHED;
    });

    check('a lapsed licence keeps the version it paid for', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense) {
        $download = $makeDownload(['title' => 'Updates', 'gateUpdatesOnExpiry' => true]);
        $old = $makeVersion($download, '1.0.0', ['dateReleased' => new DateTime('-200 days')]);

        $license = $makeLicense([
            'downloadIds' => [(int)$download->id],
            'expiryDate' => new DateTime('-100 days'),
        ]);

        return $plugin->access->checkVersion($download, $old, $license)->allowed;
    });

    check('and is turned away from the build released after it lapsed', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense) {
        $download = $makeDownload(['title' => 'Updates gated', 'gateUpdatesOnExpiry' => true]);
        $makeVersion($download, '1.0.0', ['dateReleased' => new DateTime('-200 days'), 'makeCurrent' => false]);
        $new = $makeVersion($download, '2.0.0', ['dateReleased' => new DateTime('-10 days')]);

        $license = $makeLicense([
            'downloadIds' => [(int)$download->id],
            'expiryDate' => new DateTime('-100 days'),
        ]);

        $verdict = $plugin->access->checkVersion($download, $new, $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_UPDATE_GATED
            ?: 'reason ' . $verdict->reason;
    });

    check('without update gating, a lapsed licence downloads nothing at all', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense) {
        $download = $makeDownload(['title' => 'Hard expiry', 'gateUpdatesOnExpiry' => false]);
        $version = $makeVersion($download, '1.0.0', ['dateReleased' => new DateTime('-200 days')]);

        $license = $makeLicense([
            'downloadIds' => [(int)$download->id],
            'expiryDate' => new DateTime('-100 days'),
        ]);

        $verdict = $plugin->access->checkVersion($download, $version, $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_EXPIRED
            ?: 'reason ' . $verdict->reason;
    });

    check('a draft version is never deliverable', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense) {
        $download = $makeDownload(['title' => 'Drafted']);
        $draft = $makeVersion($download, '0.9.0', ['status' => Version::STATUS_DRAFT, 'makeCurrent' => false]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $verdict = $plugin->access->checkVersion($download, $draft, $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_UNAVAILABLE;
    });

    check('a file belonging to another version is refused', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense) {
        $download = $makeDownload(['title' => 'Crossed']);
        $one = $makeVersion($download, '1.0.0', ['dateReleased' => new DateTime('-5 days'), 'makeCurrent' => false]);
        $two = $makeVersion($download, '2.0.0');
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $verdict = $plugin->access->checkFile($download, $two, $one->getFiles()[0], $license);

        return !$verdict->allowed && $verdict->reason === AccessVerdict::REASON_UNAVAILABLE;
    });

    check('the most generous usable licence is the one that gets used', function() use ($plugin, $makeDownload, $makeLicense, $suffix) {
        $download = $makeDownload(['title' => 'Two licences']);
        $email = "twice-$suffix@example.com";

        $lapsed = $makeLicense([
            'downloadIds' => [(int)$download->id],
            'email' => $email,
            'expiryDate' => new DateTime('-10 days'),
        ]);

        $live = $makeLicense([
            'downloadIds' => [(int)$download->id],
            'email' => $email,
            'expiryDate' => new DateTime('+300 days'),
        ]);

        $found = $plugin->access->findLicense($download, null, $email);

        return $found !== null && (int)$found->id === (int)$live->id
            ?: 'picked ' . ($found?->id ?? 'nothing') . ', wanted ' . $live->id;
    });

    // ---------------------------------------------------------------------------------------------
    section('Links');

    check('the token is never stored, only its hash', function() use ($plugin, $licensed, $v2, $license) {
        $token = $plugin->links->mintForFile($v2->getFiles()[0], $v2, $licensed, $license);

        $stored = (new craft\db\Query())
            ->select(['tokenHash'])
            ->from([Table::TOKENS])
            ->where(['id' => $token->id])
            ->scalar();

        return $stored === hash('sha256', $token->token)
            && $stored !== $token->token
            && strlen((string)$token->token) >= 40;
    });

    check('a minted link redeems to an allowed verdict', function() use ($plugin, $licensed, $v2, $license) {
        $token = $plugin->links->mintForFile($v2->getFiles()[0], $v2, $licensed, $license);
        [$row, $verdict] = $plugin->links->redeem((string)$token->token);

        return $row !== null && $verdict->allowed ?: 'reason ' . $verdict->reason;
    });

    check('an unknown token is refused', function() use ($plugin) {
        [$row, $verdict] = $plugin->links->redeem('not-a-real-token');

        return $row === null && $verdict->reason === AccessVerdict::REASON_TOKEN_UNKNOWN;
    });

    check('an expired token is refused', function() use ($plugin, $licensed, $v2, $license) {
        $token = $plugin->links->mintForFile($v2->getFiles()[0], $v2, $licensed, $license);

        Craft::$app->getDb()->createCommand()
            ->update(Table::TOKENS, ['expiryDate' => Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $token->id])
            ->execute();

        [, $verdict] = $plugin->links->redeem((string)$token->token);

        return $verdict->reason === AccessVerdict::REASON_TOKEN_EXPIRED;
    });

    check('a single-use token is refused the second time', function() use ($plugin, $licensed, $v2, $license) {
        $token = $plugin->links->mintForFile($v2->getFiles()[0], $v2, $licensed, $license, ['maxUses' => 1]);

        [$row, $first] = $plugin->links->redeem((string)$token->token);
        $plugin->links->spend($row);
        [, $second] = $plugin->links->redeem((string)$token->token);

        return $first->allowed && $second->reason === AccessVerdict::REASON_TOKEN_SPENT;
    });

    check('revoking a licence kills the links already in somebody’s inbox', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense) {
        $download = $makeDownload(['title' => 'Refunded']);
        $version = $makeVersion($download, '1.0.0');
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $token = $plugin->links->mintForFile($version->getFiles()[0], $version, $download, $license);

        $plugin->licenses->revoke($license, 'refund');

        [$row, $verdict] = $plugin->links->redeem((string)$token->token);

        return $row === null && $verdict->reason === AccessVerdict::REASON_TOKEN_UNKNOWN
            ?: 'the link still resolved: ' . $verdict->reason;
    });

    check('a token with no version pinned follows the current one', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense) {
        $download = $makeDownload(['title' => 'Floating']);
        $makeVersion($download, '1.0.0', ['dateReleased' => new DateTime('-30 days'), 'makeCurrent' => false]);
        $current = $makeVersion($download, '2.0.0');
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $token = $plugin->links->mint([
            'type' => LinkToken::TYPE_DOWNLOAD,
            'licenseId' => (int)$license->id,
            'downloadId' => (int)$download->id,
        ]);

        [, $verdict] = $plugin->links->redeem((string)$token->token);

        return $verdict->allowed && (int)$verdict->version->id === (int)$current->id
            ?: 'resolved to ' . ($verdict->version?->version ?? 'nothing');
    });

    check('pruning removes expired tokens and leaves live ones', function() use ($plugin, $licensed, $v2, $license) {
        $live = $plugin->links->mintForFile($v2->getFiles()[0], $v2, $licensed, $license);
        $dead = $plugin->links->mintForFile($v2->getFiles()[0], $v2, $licensed, $license);

        Craft::$app->getDb()->createCommand()
            ->update(Table::TOKENS, ['expiryDate' => Db::prepareDateForDb(new DateTime('-1 day'))], ['id' => $dead->id])
            ->execute();

        $plugin->links->prune();

        $liveExists = (new craft\db\Query())->from([Table::TOKENS])->where(['id' => $live->id])->exists();
        $deadExists = (new craft\db\Query())->from([Table::TOKENS])->where(['id' => $dead->id])->exists();

        return $liveExists && !$deadExists;
    });

    // ---------------------------------------------------------------------------------------------
    section('Activations');

    check('an instance is normalised so one machine is one seat', function() {
        return Activation::normalizeInstance('https://WWW.Example.com/') === 'example.com'
            && Activation::normalizeInstance('example.com') === 'example.com';
    });

    check('activating takes a seat', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Seats', 'activationLimit' => 2]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $result = $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');
        $fresh = $plugin->licenses->getLicenseById((int)$license->id);

        return $result->success && $fresh->activationCount === 1
            ?: $result->code . ' count=' . $fresh->activationCount;
    });

    check('activating the same machine twice is still one seat', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Reinstall', 'activationLimit' => 1]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');
        $second = $plugin->activations->activate((string)$license->licenseKey, 'https://ONE.example.com/');

        $fresh = $plugin->licenses->getLicenseById((int)$license->id);

        return $second->success && $fresh->activationCount === 1
            ?: $second->code . ' count=' . $fresh->activationCount;
    });

    check('the seat limit is enforced', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'One seat', 'activationLimit' => 1]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');
        $second = $plugin->activations->activate((string)$license->licenseKey, 'https://two.example.com');

        return !$second->success && $second->code === ActivationResult::SEAT_LIMIT ?: $second->code;
    });

    check('deactivating frees the seat without underflowing the counter', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Freed', 'activationLimit' => 1]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');
        $plugin->activations->deactivate((string)$license->licenseKey, 'https://one.example.com');

        $fresh = $plugin->licenses->getLicenseById((int)$license->id);
        $again = $plugin->activations->activate((string)$license->licenseKey, 'https://two.example.com');

        return $fresh->activationCount === 0 && $again->success
            ?: 'count=' . $fresh->activationCount . ' ' . $again->code;
    });

    check('deactivating a seat that was never taken does not go below zero', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Underflow', 'activationLimit' => 1]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');
        $plugin->activations->deactivate((string)$license->licenseKey, 'https://one.example.com');
        $plugin->activations->deactivate((string)$license->licenseKey, 'https://one.example.com');

        $count = (int)(new craft\db\Query())
            ->select(['activationCount'])
            ->from([Table::LICENSES])
            ->where(['id' => $license->id])
            ->scalar();

        return $count === 0 ?: "count=$count";
    });

    check('an expired licence cannot be activated, and is told why', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Lapsed seat']);
        $license = $makeLicense([
            'downloadIds' => [(int)$download->id],
            'expiryDate' => new DateTime('-1 day'),
        ]);

        $result = $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');

        return !$result->success && $result->code === ActivationResult::EXPIRED ?: $result->code;
    });

    check('an unknown key says so rather than leaking anything', function() use ($plugin) {
        $result = $plugin->activations->activate('NOPE-NOPE-NOPE-NOPE', 'https://one.example.com');

        return !$result->success
            && $result->code === ActivationResult::UNKNOWN_KEY
            && $result->license === null;
    });

    check('recounting puts a drifted activation count right', function() use ($plugin, $makeDownload, $makeLicense) {
        $download = $makeDownload(['title' => 'Drift', 'activationLimit' => 3]);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');

        Craft::$app->getDb()->createCommand()
            ->update(Table::LICENSES, ['activationCount' => 7], ['id' => $license->id])
            ->execute();

        $plugin->licenses->recountActivations((int)$license->id);

        $count = (int)(new craft\db\Query())
            ->select(['activationCount'])
            ->from([Table::LICENSES])
            ->where(['id' => $license->id])
            ->scalar();

        return $count === 1 ?: "count=$count";
    });

    // ---------------------------------------------------------------------------------------------
    section('VAT');

    check('same country is domestic', function() use ($plugin) {
        return $plugin->vat->determineTreatment('DE', 'DE', false) === Invoice::TREATMENT_DOMESTIC;
    });

    check('another EU country with no proof is taxed where the customer is', function() use ($plugin) {
        return $plugin->vat->determineTreatment('DE', 'FR', false) === Invoice::TREATMENT_OSS;
    });

    check('another EU country with a confirmed VAT number is reverse charged', function() use ($plugin) {
        return $plugin->vat->determineTreatment('DE', 'FR', true) === Invoice::TREATMENT_REVERSE_CHARGE;
    });

    check('outside the EU is outside the scope, VAT number or not', function() use ($plugin) {
        return $plugin->vat->determineTreatment('DE', 'US', false) === Invoice::TREATMENT_OUTSIDE_SCOPE
            && $plugin->vat->determineTreatment('DE', 'US', true) === Invoice::TREATMENT_OUTSIDE_SCOPE;
    });

    check('the OSS rate is the buyer’s and the domestic rate is the seller’s', function() use ($plugin) {
        $oss = $plugin->vat->rateForTreatment(Invoice::TREATMENT_OSS, 'DE', 'HU');
        $domestic = $plugin->vat->rateForTreatment(Invoice::TREATMENT_DOMESTIC, 'DE', 'HU');
        $reverse = $plugin->vat->rateForTreatment(Invoice::TREATMENT_REVERSE_CHARGE, 'DE', 'HU');

        return $oss === 27.0 && $domestic === 19.0 && $reverse === 0.0
            ?: "oss=$oss domestic=$domestic reverse=$reverse";
    });

    check('a rate is looked up by date, so an old invoice keeps its old rate', function() use ($plugin, &$created) {
        $rate = new \justinholtweb\digits\models\VatRate([
            'countryCode' => 'DE',
            'rate' => 25.0,
            'kind' => 'standard',
            'effectiveFrom' => new DateTime('+30 days'),
        ]);

        $plugin->vat->saveRate($rate);
        $created['rates'][] = (int)$rate->id;

        $today = $plugin->vat->getRate('DE', new DateTime())?->rate;
        $later = $plugin->vat->getRate('DE', new DateTime('+60 days'))?->rate;

        return $today === 19.0 && $later === 25.0 ?: "today=$today later=$later";
    });

    check('Greece’s VAT prefix is EL and its country code is GR', function() use ($plugin) {
        return $plugin->vat->vatPrefixToCountry('EL') === 'GR'
            && $plugin->vat->countryToVatPrefix('GR') === 'EL'
            && $plugin->vat->formatIsValid('EL', '123456789');
    });

    check('a mistyped VAT number fails the format check', function() use ($plugin) {
        return $plugin->vat->formatIsValid('DE', '123456789')
            && !$plugin->vat->formatIsValid('DE', '12345')
            && !$plugin->vat->formatIsValid('NL', '123456789');
    });

    check('a format check is recorded as a format check, and is not proof', function() use ($plugin) {
        $check = $plugin->vat->validateVatId('DE123456789', true);

        return $check->valid === true
            && $check->source === \justinholtweb\digits\models\VatIdCheck::SOURCE_FORMAT
            && !$check->getIsProof();
    });

    check('a human’s decision outranks the format check and is proof', function() use ($plugin) {
        $check = $plugin->vat->confirmVatIdByHand('DE123456789', true);

        return $check->getIsProof() && $check->source === \justinholtweb\digits\models\VatIdCheck::SOURCE_MANUAL;
    });

    check('splitting a VAT number ignores spaces and case', function() use ($plugin) {
        return $plugin->vat->splitVatId('de 123 456 789') === ['DE', '123456789'];
    });

    // ---------------------------------------------------------------------------------------------
    section('Invoices');

    check('invoice numbers are sequential inside a series', function() use ($plugin, $suffix) {
        $series = "checks$suffix";

        $first = $plugin->invoices->nextNumber($series);
        $second = $plugin->invoices->nextNumber($series);

        $firstNumber = (int)preg_replace('/\D/', '', substr($first, -6));
        $secondNumber = (int)preg_replace('/\D/', '', substr($second, -6));

        return $secondNumber === $firstNumber + 1 ?: "$first then $second";
    });

    check('the preview does not spend a number', function() use ($plugin, $suffix) {
        $series = "preview$suffix";

        $preview = $plugin->invoices->previewNumber($series);
        $again = $plugin->invoices->previewNumber($series);
        $actual = $plugin->invoices->nextNumber($series);

        return $preview === $again && $preview === $actual
            ?: "preview=$preview again=$again actual=$actual";
    });

    check('a credit note negates the original and marks it credited', function() use ($plugin, &$created, $suffix) {
        $invoice = new Invoice([
            'series' => "credit$suffix",
            'customerEmail' => "credit-$suffix@example.com",
            'currency' => 'EUR',
            'treatment' => Invoice::TREATMENT_DOMESTIC,
            'vatRate' => 19.0,
            'netAmount' => 100.0,
            'vatAmount' => 19.0,
            'grossAmount' => 119.0,
            'chargedTaxAmount' => 19.0,
            'dateIssued' => new DateTime(),
        ]);

        $plugin->invoices->save($invoice);
        $created['invoices'][] = (int)$invoice->id;

        $credit = $plugin->invoices->credit($invoice, null, 'checks');
        $created['invoices'][] = (int)$credit->id;

        $original = $plugin->invoices->getInvoiceById((int)$invoice->id);

        return $credit->grossAmount === -119.0
            && $credit->creditOfId === (int)$invoice->id
            && $original->status === Invoice::STATUS_CREDITED
            ?: 'gross=' . $credit->grossAmount . ' status=' . $original->status;
    });

    check('a partial credit note is proportional', function() use ($plugin, &$created, $suffix) {
        $invoice = new Invoice([
            'series' => "partial$suffix",
            'customerEmail' => "partial-$suffix@example.com",
            'currency' => 'EUR',
            'netAmount' => 100.0,
            'vatAmount' => 20.0,
            'grossAmount' => 120.0,
            'dateIssued' => new DateTime(),
        ]);

        $plugin->invoices->save($invoice);
        $created['invoices'][] = (int)$invoice->id;

        $credit = $plugin->invoices->credit($invoice, 60.0);
        $created['invoices'][] = (int)$credit->id;

        return $credit->grossAmount === -60.0 && $credit->vatAmount === -10.0
            ?: 'gross=' . $credit->grossAmount . ' vat=' . $credit->vatAmount;
    });

    check('the required reverse-charge wording is on the document', function() {
        $invoice = new Invoice(['treatment' => Invoice::TREATMENT_REVERSE_CHARGE, 'dateIssued' => new DateTime()]);

        return str_contains($invoice->getVatNote(), '2006/112/EC');
    });

    check('evidence knows the difference between one piece, two, and a contradiction', function() {
        $one = new Invoice(['evidence' => ['billingCountry' => 'FR'], 'dateIssued' => new DateTime()]);
        $two = new Invoice(['evidence' => ['billingCountry' => 'FR', 'ipCountry' => 'FR'], 'dateIssued' => new DateTime()]);
        $clash = new Invoice(['evidence' => ['billingCountry' => 'FR', 'ipCountry' => 'DE'], 'dateIssued' => new DateTime()]);

        return !$one->getEvidenceIsSufficient()
            && $two->getEvidenceIsSufficient()
            && $clash->getEvidenceConflicts()
            && !$clash->getEvidenceIsSufficient();
    });

    check('the OSS report only counts sales taxed in the customer’s country', function() use ($plugin, &$created, $suffix) {
        $window = new DateTime('-1 hour');

        // Measured as a delta rather than an absolute. The report groups by country, so anything
        // else on this install that sold to France in the last hour is in the same row — and a
        // suite that asserts on the absolute figure fails because of somebody else's fixture.
        $frenchNet = static function() use ($plugin, $window): float {
            $rows = $plugin->vat->ossReport($window, new DateTime('+1 hour'));
            $french = array_values(array_filter($rows, static fn(array $row) => $row['buyerCountry'] === 'FR'));

            return $french !== [] ? (float)$french[0]['net'] : 0.0;
        };

        $before = $frenchNet();

        foreach ([
            [Invoice::TREATMENT_OSS, 'FR', 100.0, 20.0],
            [Invoice::TREATMENT_REVERSE_CHARGE, 'FR', 500.0, 0.0],
            [Invoice::TREATMENT_OUTSIDE_SCOPE, 'US', 900.0, 0.0],
        ] as [$treatment, $country, $net, $vat]) {
            $invoice = new Invoice([
                'series' => "oss$suffix",
                'customerEmail' => "oss-$suffix@example.com",
                'currency' => 'EUR',
                'treatment' => $treatment,
                'buyerCountry' => $country,
                'vatRate' => 20.0,
                'netAmount' => $net,
                'vatAmount' => $vat,
                'grossAmount' => $net + $vat,
                'dateIssued' => new DateTime(),
            ]);

            $plugin->invoices->save($invoice);
            $created['invoices'][] = (int)$invoice->id;
        }

        // Only the OSS row moved: the reverse charge and the out-of-scope sale are 1,400 between
        // them and belong on the return as different figures.
        $added = $frenchNet() - $before;

        return abs($added - 100.0) < 0.001 ?: "FR net moved by $added";
    });

    // ---------------------------------------------------------------------------------------------
    section('Log');

    check('a refusal is written with its reason', function() use ($plugin, $licensed) {
        $verdict = AccessVerdict::deny(AccessVerdict::REASON_LIMIT_REACHED, $licensed);
        $plugin->log->denied($verdict, ['email' => 'log@example.com']);

        $rows = $plugin->log->find(['event' => Log::EVENT_DENIED, 'downloadId' => (int)$licensed->id], 5);

        return $rows !== [] && $rows[0]['reason'] === AccessVerdict::REASON_LIMIT_REACHED;
    });

    check('the log joins the download’s name without hiding orphaned rows', function() use ($plugin, $licensed) {
        $rows = $plugin->log->find(['downloadId' => (int)$licensed->id], 5);

        return $rows !== [] && array_key_exists('downloadTitle', $rows[0]);
    });

    check('pruning respects the retention window', function() use ($plugin, $licensed) {
        $plugin->log->write(Log::EVENT_DOWNLOAD, ['downloadId' => (int)$licensed->id, 'email' => 'old@example.com']);

        $id = (int)(new craft\db\Query())
            ->select(['id'])
            ->from([Table::LOG])
            ->orderBy(['id' => SORT_DESC])
            ->scalar();

        Craft::$app->getDb()->createCommand()
            ->update(Table::LOG, ['dateCreated' => Db::prepareDateForDb(new DateTime('-400 days'))], ['id' => $id])
            ->execute();

        $plugin->log->prune(365);

        return !(new craft\db\Query())->from([Table::LOG])->where(['id' => $id])->exists();
    });

    // ---------------------------------------------------------------------------------------------
    section('Editions');

    check('Pro allows everything the split promises', function() {
        return Edition::allowsLicenseKeys(true)
            && Edition::allowsActivations(true)
            && Edition::allowsPortal(true)
            && Edition::allowsInvoicing(true)
            && Edition::allowsUpdateNotices(true)
            && Edition::maxVersions(true) === null;
    });

    check('Lite withholds the software-vendor half and caps versions', function() {
        return !Edition::allowsLicenseKeys(false)
            && !Edition::allowsActivations(false)
            && !Edition::allowsPortal(false)
            && !Edition::allowsInvoicing(false)
            && Edition::maxVersions(false) === Edition::LITE_MAX_VERSIONS;
    });

    check('Lite refuses the activation API at the service, not just in the UI', function() use ($plugin, $makeDownload, $makeLicense, $setEdition) {
        $download = $makeDownload(['title' => 'Lite seats']);
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $setEdition(Plugin::EDITION_LITE);

        $result = $plugin->activations->activate((string)$license->licenseKey, 'https://one.example.com');

        $setEdition(Plugin::EDITION_PRO);

        return !$result->success && $result->code === ActivationResult::DISABLED ?: $result->code;
    });

    check('Lite still lets a download link work, because the licence is the entitlement', function() use ($plugin, $makeDownload, $makeVersion, $makeLicense, $setEdition) {
        $download = $makeDownload(['title' => 'Lite download']);
        $version = $makeVersion($download, '1.0.0');
        $license = $makeLicense(['downloadIds' => [(int)$download->id]]);

        $setEdition(Plugin::EDITION_LITE);

        $verdict = $plugin->access->checkFile($download, $version, $version->getFiles()[0], $license);

        $setEdition(Plugin::EDITION_PRO);

        return $verdict->allowed ?: 'reason ' . $verdict->reason;
    });

    check('Lite caps versions at two', function() use ($plugin, $makeDownload, $makeVersion, $setEdition) {
        $download = $makeDownload(['title' => 'Lite versions']);
        $makeVersion($download, '1.0.0', ['dateReleased' => new DateTime('-3 days'), 'makeCurrent' => false]);
        $makeVersion($download, '2.0.0');

        $setEdition(Plugin::EDITION_LITE);
        $plugin->versions->clearCaches();

        $third = new Version(['downloadId' => (int)$download->id, 'version' => '3.0.0']);
        $third->setFiles([new DownloadFile(['name' => 'Third', 'url' => 'https://example.com/3.zip'])]);
        $saved = $plugin->versions->saveVersion($third);

        $setEdition(Plugin::EDITION_PRO);

        return !$saved && $third->hasErrors('version') ?: 'Lite accepted a third version';
    });

    // ---------------------------------------------------------------------------------------------
    section('Commerce bridge');

    check('the bridge is only wired up when Commerce is actually there', function() {
        return Plugin::commerceIsReady() === (
            class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce')
        );
    });

    check('a SKU with no relation still resolves to a download', function() use ($plugin, $licensed) {
        $downloads = $plugin->downloads->getDownloadsFor(null, (string)$licensed->sku);

        return count($downloads) === 1 && (int)$downloads[0]->id === (int)$licensed->id;
    });

    check('a SKU nothing matches resolves to nothing', function() use ($plugin) {
        return $plugin->downloads->getDownloadsFor(null, 'NO-SUCH-SKU-EVER') === [];
    });
} finally {
    // ---------------------------------------------------------------------------------------------
    section('Cleanup');

    check('the suite’s licences are gone', function() use ($created) {
        foreach ($created['licenses'] as $id) {
            $license = License::find()->id($id)->status(null)->one();

            if ($license !== null) {
                Craft::$app->getElements()->deleteElement($license, true);
            }
        }

        $remaining = (int)License::find()->id($created['licenses'])->status(null)->count();

        return $remaining === 0 ?: "$remaining left";
    });

    check('the suite’s downloads are gone, and took their versions with them', function() use ($created) {
        foreach ($created['downloads'] as $id) {
            $download = Download::find()->id($id)->status(null)->one();

            if ($download !== null) {
                Craft::$app->getElements()->deleteElement($download, true);
            }
        }

        $versions = (int)(new craft\db\Query())
            ->from([Table::VERSIONS])
            ->where(['downloadId' => $created['downloads']])
            ->count();

        return $versions === 0 ?: "$versions versions left";
    });

    check('the suite’s invoices and rates are gone', function() use ($created) {
        $db = Craft::$app->getDb();

        if ($created['invoices'] !== []) {
            $db->createCommand()->delete(Table::INVOICES, ['creditOfId' => $created['invoices']])->execute();
            $db->createCommand()->delete(Table::INVOICES, ['id' => $created['invoices']])->execute();
        }

        if ($created['rates'] !== []) {
            $db->createCommand()->delete(Table::VATRATES, ['id' => $created['rates']])->execute();
        }

        $left = (int)(new craft\db\Query())->from([Table::INVOICES])->where(['id' => $created['invoices']])->count();

        return $left === 0 ?: "$left invoices left";
    });

    check('the settings and the edition are back as they were', function() use ($plugin, $originalSettings, $originalEdition) {
        $plugin->getSettings()->setAttributes($originalSettings, false);
        $plugin->edition = $originalEdition;

        return $plugin->edition === $originalEdition;
    });
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
