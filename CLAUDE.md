# Digits — Craft CMS 5 Plugin

## Project Overview

Digits is gated digital downloads and software licensing for Craft 5. Distributed as
`justinholtweb/craft-digits`. **Lite is free; Pro is $129 with a $99/year renewal.**

Craft's own `craftcms/digital-products` gives Commerce a digital purchasable and stops there. Digits
is everything after the sale — expiring links, download limits, versioned files, licence keys with
activation limits, a customer portal, refunds that revoke, and EU VAT invoicing. Modelled on Easy
Digital Downloads' feature set, built around Craft elements rather than post meta.

Pairs with Abacus (order numbers), Headcount and Dispatch.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- **Craft Commerce 5.0+ is optional** — a `suggest`, never a `require`
- No build step: no asset bundles, no JS beyond a few `Craft.sendActionRequest` calls in CP templates
- No PDF library. Invoices are print-ready HTML — dompdf's security advisories block installs
  (see `[[craft-microscope-gotchas]]`)

## Architecture

### Namespace & package

- Namespace: `justinholtweb\digits`
- Package: `justinholtweb/craft-digits`
- Handle: `digits`

### Commerce is optional, and that shapes everything

`services\Orders` is the *only* class that knows an order from a licence, behind
`Plugin::commerceIsReady()`. Delete it and downloads, licences, links, limits and the portal all
still work; what stops working is buying one.

This is not politeness towards non-Commerce sites. It is what stops the licence model becoming "a
row that belongs to an order", which is the shape that makes refunds, renewals and manual grants
each need their own special case.

**No Digits table has a foreign key into a Commerce table.** `orderId` is a plain integer beside a
copy of the order's reference. A real FK would make the install migration fail on a site without
Commerce and would make uninstalling Commerce impossible on a site with it. There is a check for
this in the suite.

### The licence is the single entitlement

One row means one person may download one set of files, this many times, until this date, on this
many machines. There is no separate purchase or grant concept beside it — two tables answering the
same question is how a shop refunds an order and leaves the access behind.

A licence covers **many** downloads through `digits_license_downloads`, with no "primary download"
column next to it. A bundle is one key unlocking three products, and a schema with both a column and
a table has two answers to "what does this key unlock".

Where a bundle's parts disagree: the **strictest** limit wins and the **longest** access window
wins. A limit is a cost the shop carries; access is a promise it made.

### A download link is a row, not a signature

The whole reason: **a refund has to be able to kill a link already in somebody's inbox**, and a
stateless signed URL cannot be revoked. Rows also count redemptions and answer "was this used, and
from where" weeks later.

The row stores `sha256($token)`, never the token. `Links::mint()` returns the token once.

**Redeeming does not authorise.** `Links::redeem()` answers "which licence is this link for" and
hands that licence to `Access`, which asks exactly the questions it would ask a signed-in customer.
A token is a claim about identity, not an entitlement.

### One verdict

`services\Access` is the only thing that decides whether somebody may have a file, and every caller
reads the same `AccessVerdict`: the Twig helper drawing a button, the controller minting a link, the
portal listing what a customer owns, and the controller handing over bytes.

That is not tidiness — it is the only arrangement in which a template cannot offer a download the
delivery endpoint would refuse, which is otherwise the most common bug in software of this shape and
the one the *customer* reports.

`check()` → `checkVersion()` → `checkFile()`, layered rather than folded together, because "you do
not have this" is a purchase and "your access ended before this build came out" is a renewal.

### Limits are conditional updates, never check-then-write

`Licenses::spend()` and `Activations::claimSeat()` are both
`UPDATE … SET n = n + 1 WHERE n < limit`, and the affected-row count *is* the decision. Two tabs,
two clicks, one download left: a read followed by a write lets both through.

### A version is a release; a file is an artefact

`dateReleased` is load-bearing. It is what "your licence covers updates until March" is measured
against, which is why publishing is a separate act from saving and why a live version always has
one.

Exactly one version per download is current, and `Versions::makeCurrent()` is the only thing allowed
to set the flag — clear-then-set inside a transaction, in that order, so a reader between the two
never sees two current versions.

### Invoices are snapshots, and corrections are credit notes

Every figure and address is copied on to the invoice at issue time. The order goes on changing;
a filed document may not. Nothing edits or deletes an issued invoice.

Invoice numbers are sequential and gapless *within a series* and unique *across the install* —
which is what an invoice number has to be. A format with no `{series}` token collides the moment a
second series starts, so `nextNumber()` bumps the counter past a taken number rather than the unique
index being weakened.

### VAT: determine and document, never charge

Commerce's tax engine decides what the customer pays. Digits works out what the treatment should
have been, prints it with the directive's required wording, records `chargedTaxAmount` beside
`vatAmount`, and flags the gap. Quietly restating a charge is how a shop ends up with books that do
not reconcile.

VIES is opt-in because it is an outbound request during checkout to a service that is often down.
The offline path checks the country prefix and shape, and `VatIdCheck::getIsProof()` is false for
it — Digits will not claim the reverse charge on a format check alone.

### Data model

Thirteen tables. The ones worth explaining are explained in `src/migrations/Install.php`'s docblock.

## Traps found while building this

- **Yii skips an inline validator when the attribute is empty**, and an empty array counts as empty
  — so a rule whose entire job is to reject "nothing chosen" is skipped in exactly that case. Three
  rules here needed `'skipOnEmpty' => false`: the download's groups, the licence's downloads, and a
  released version's files. All three passed their tests only because the tests were written first.
- **A file model saved by `Versions::saveFiles()` has to be handed its own `versionId` back**, not
  just have it written to the row. `Access::checkFile()` compares them, so a freshly saved file that
  never learned its parent is refused as "unavailable" — and only on the request that saved it,
  which is what makes it hard to find.
- **`render()` and `currentUser()` are already taken on Craft's web controller** — `render()` is
  public, `currentUser()` is static — and PHP refuses to load the class at all if a subclass narrows
  either. The failure is a fatal error on every request the controller serves, with nothing in it
  about the name that collided. `PortalController` has a `renderPortal()` because of this.
- **`status` is `Element::getStatus()`.** A public `$status` property silently shadows the method the
  element index, the status menu and every `->status()` query rely on. The licences table keeps the
  obvious column name and the query aliases it to `licenseStatus` on the way in. Same family as the
  Bandage trap.
- **Twig has no `sum` filter.** Summing an array in a template is a `reduce`; summing it in the
  controller is one line of PHP.
- **Element index sources are `Craft::configure()`d on to the query**, so a criteria key with only a
  *method* behind it throws `UnknownPropertyException` rather than calling it. `expiringWithin` is a
  property *and* a method for this reason.
- **`GREATEST(unsignedColumn - 1, 0)` is a trap**: MySQL evaluates `0 - 1` before `GREATEST` sees it
  and wraps to 18446744073709551615. `CAST(… AS SIGNED)` first. Same as the Hire trap.
- **Element date params must not be pre-converted to UTC** — pass `format(DATE_ATOM)`, not
  `Db::prepareDateForDb()`, or the window silently matches nothing. Same as the Abacus trap.
- **`switchEdition()` writes to project config**, and a script that writes to project config twice
  in a run hits `StaleResourceException` on the second write. The checks set `$plugin->edition`
  in-process instead and restore it in the `finally`.
- **A raw `Query` row's date is a UTC string, and `|datetime` reads a string as the site's own zone.**
  The activity log is read as rows, not models, so every timestamp on the log screen and the licence
  screen was shown hours off until `Log::find()` started converting `dateCreated` with
  `DateTimeHelper::toDateTime()`. Anything else read as raw rows needs the same.
- **`savePluginSettings()` replaces rather than merges**, so a multi-screen settings controller has
  to start from the live model and write the whole thing back or it silently wipes the other
  screens. Same as the Trackr trap.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-digits/tests/integration/checks.php   # 95 checks
ddev exec bash -c 'find /var/www/craft-digits/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning: every run creates its own downloads, versions and
licences under a random suffix and deletes them in a `finally`. They switch the edition **in
process** rather than through project config, and they mutate the live settings model in-process and
restore it — which works because settings are a memoized model per request, and would not survive a
real HTTP boundary.

**Assertions against the OSS report must be deltas, not absolutes.** The report groups by country,
so anything else on the install that sold to France in the same window lands in the same row.

The CP and the front end are smoke-tested by hand over curl with a real admin session — every CP
screen, both edit screens, the portal (guest and token), the invoice document, the download endpoint
and all four API endpoints. `ddev start` on this harness frequently needs a second attempt; the
first fails on a `ddev-global-cache` chown.

## Coding conventions

- `Craft::t('digits', '…')` for user-facing strings; `src/translations/en/digits.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`,
  and answer them with `asSuccess()` rather than a flash and a redirect (a 302 answered to an XHR is
  how a secondary action appears to do nothing at all)
- Never mark plugin settings `required`
- Nothing outside `services\Orders` mentions Commerce
- Nothing in the Twig API may authorise anything — it asks `Access` like everybody else
