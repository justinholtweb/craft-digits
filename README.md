# Digits

Gated digital downloads and software licensing for Craft CMS 5.

Craft's own `craftcms/digital-products` gives Commerce a digital purchasable and stops there. What
a shop selling files needs afterwards is the part that is missing, and that is what Digits is:

- **Expiring download links.** Minted per click, dead in a day, and — because they are rows and not
  signed URLs — revocable.
- **Download limits and counters.** Enforced by the database rather than by a check-then-write, so
  two tabs cannot both spend the last one.
- **Versioned files with release notices.** A release is several files; the release date is what
  "your licence covers updates until March" is measured against.
- **Licence keys with activation limits.** A real key, a real API, and seats that a reinstall does
  not silently burn through.
- **A customer portal.** Signed-in or guest, with the files, the keys, the seats and the invoices.
- **Refunds that actually revoke.** The links already sitting in somebody's inbox stop working.
- **EU VAT invoicing.** Place-of-supply treatment, the reverse-charge wording the directive
  requires, evidence capture and the quarterly OSS return.

**Commerce is optional.** Gating a file behind a user group, issuing a licence by hand, expiring a
link and counting a delivery are all Craft-only features. The whole Commerce integration is one
service behind one guard.

## Requirements

- Craft CMS 5.3+
- PHP 8.2+
- Craft Commerce 5.0+ — only to sell downloads and to issue invoices

## Installation

```sh
composer require justinholtweb/craft-digits
php craft plugin/install digits
```

## Editions

**Lite is a working download shop, not a demo.** Selling files, protecting them behind expiring
links, capping how many times each buyer may take them, versioning them and revoking them when the
money goes back are all in Lite, uncapped — there is no limit on the number of downloads or the
number of buyers.

**Pro** ($129, then $99/year for updates) is the software vendor's half: licence keys that machines can check, activation seats, the
front-end portal, release notices, update gating and the VAT paperwork.

| | Lite | Pro |
|---|---|---|
| Price | Free | $129 + $99/year |
| Downloads, versions, expiring links, limits | ✓ | ✓ |
| Licences as entitlements, refund revocation | ✓ | ✓ |
| Download activity log | ✓ | ✓ |
| Versions per download | 2 | unlimited |
| Licence keys and the activation API | | ✓ |
| Activation seats | | ✓ |
| Customer portal | | ✓ |
| Release notices and update gating | | ✓ |
| VAT invoices, VAT ID checking, OSS return | | ✓ |

## Attaching downloads to what you sell

Add a **Downloads** field to a Commerce variant's field layout and relate the files. That is the
whole bridge — a purchase of the variant issues a licence covering everything related.

A download can also carry a **SKU**, which Digits matches against a line item when nothing relates
it. Use the field where you can; use the SKU where somebody else owns the product field layout.

The same field works anywhere: put it on an entry to gate a PDF behind a user group, with no
Commerce involved at all.

## Templating

```twig
{% set download = entry.downloadsField.one() %}
{% set verdict = craft.digits.can(download) %}

{% if verdict.allowed %}
    {% for link in craft.digits.linksFor(download) %}
        <a href="{{ link.url }}">{{ link.file.name }} ({{ link.file.sizeLabel }})</a>
    {% endfor %}
{% else %}
    <p>{{ verdict.message }}</p>
{% endif %}
```

`craft.digits.can()` returns the same verdict the delivery endpoint will produce, which is what
makes it impossible to render a button the download would refuse.

Everything else is an ordinary element query:

```twig
{% set mine = craft.digits.myLicenses(true) %}
{% set manuals = craft.digits.downloads.accessType('public').all() %}
```

## The activation API

```
POST /digits/api/activate    key, instance, label
POST /digits/api/deactivate  key, instance
POST /digits/api/check       key, instance
POST /digits/api/versions    key, download
```

Every response is `{ success, code, message, … }`. The `code` is machine-readable — `unknown-key`,
`expired`, `seat-limit`, `not-activated` — because those lead to different dialogues in your
customer's software.

`versions` returns only the releases the licence is entitled to, each file with a freshly minted
download URL, so an updater and the download endpoint can never disagree.

## Console commands

```sh
php craft digits/licenses/issue --email=a@b.com --downloads=toolkit,manual
php craft digits/licenses/repair --dry-run     # orders whose completion never issued
php craft digits/licenses/expire               # stamp lapsed licences
php craft digits/licenses/recount              # put activation counts right
php craft digits/downloads/check               # every live version has files, every file has bytes
php craft digits/downloads/notify <versionId>  # send release notices again
php craft digits/downloads/prune-links
php craft digits/invoices/backfill --dry-run
php craft digits/invoices/report 2026-04-01 2026-07-01
```

## VAT, honestly

Digits **determines and documents** VAT. It does not charge it — your tax engine decides what a
customer pays, and quietly restating that figure on the invoice is how a shop ends up with books
that do not reconcile.

So Digits works out what the treatment should have been (domestic, the customer's country, reverse
charge, or outside the scope), prints it with the wording the directive requires, records what
Commerce actually took beside it, and puts the invoices where the two disagree on one screen.

VAT ID checking against VIES is off by default and opt-in: it is an outbound request during
somebody's checkout to a service that is frequently down. With it off, Digits still checks the
country prefix and the shape — which catches typos, and which is *not* the same evidence. The
invoice records which of the two it had, and will not claim the reverse charge on a format check
alone unless a human has confirmed the number.

## Documentation

Full documentation: <https://justinholt.com/plugins/craft-digits/docs>

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Usage](docs/usage.md)
- [Licensing and the activation API](docs/licensing.md)
- [EU VAT and invoicing](docs/vat.md)
- [FAQ](docs/faq.md)
- [Troubleshooting](docs/troubleshooting.md)

## Licence

The Craft License. See [LICENSE.md](LICENSE.md).
