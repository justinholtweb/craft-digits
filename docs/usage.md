# Usage

## The shape of the thing

```
Download  ──< Version ──< File
    │
    └──< Licence >── Activation
              │
              └── Token (a download link)
```

- A **download** is the thing somebody buys or is given: rules plus a field layout.
- A **version** is one release of it. A release has a date, which is what update entitlement is
  measured against.
- A **file** is one artefact in a release — the Windows build, the macOS build, the checksum.
- A **licence** is the single entitlement: one row means one person may download one set of files,
  this many times, until this date, on this many machines.
- A **token** is one download link. It is a row, not a signature, which is what makes it revocable.

## Selling a download

1. Create the download and give it at least one released version.
2. Put a Downloads field on your variant's field layout and relate it.
3. Sell the variant.

On completion, Digits issues one licence per line item, covering every download that line sells.
The customer is emailed their files (and their key, on Pro), and everything is on their account
page.

### Bundles

A licence covers *many* downloads. Relate three downloads to one variant and the buyer gets one
key that unlocks all three.

Where a bundle's parts disagree, Digits takes the stricter of the two limits (the tightest download
cap wins) and the more generous of the two windows (the longest access period wins). A limit is a
cost the shop carries; access is a promise it made.

## Gating a file with no Commerce

Set the download's access to **Signed-in users** or **Chosen user groups**, put a Downloads field
on the entry, and template it:

```twig
{% for link in craft.digits.linksFor(entry.manual.one()) %}
    <a href="{{ link.url }}">{{ link.file.name }}</a>
{% endfor %}
```

Nobody needs a licence, and the link still expires and is still counted.

## Templating

### The verdict

```twig
{% set verdict = craft.digits.can(download) %}

{% if verdict.allowed %}
    …
{% elseif verdict.isAuthenticationProblem %}
    <a href="{{ url('login') }}">Sign in to download this</a>
{% else %}
    <p>{{ verdict.message }}</p>
{% endif %}
```

`can()` returns the same object the delivery endpoint produces. A template physically cannot offer
a download that the endpoint would refuse.

### Links

```twig
{% for link in craft.digits.linksFor(download) %}
    {% if link.url %}
        <a href="{{ link.url }}">{{ link.file.name }} — {{ link.file.sizeLabel }}</a>
    {% else %}
        <span>{{ link.file.name }} — {{ link.verdict.message }}</span>
    {% endif %}
{% endfor %}
```

Minting a link is a write, so call `linksFor()` where a button is actually being drawn — not
speculatively in a loop over a catalogue.

### Versions somebody is entitled to

```twig
{% for version in craft.digits.versionsFor(download) %}
    <h3>{{ version.version }} <small>{{ version.dateReleased|date('medium') }}</small></h3>
    {{ version.releaseNotes|md }}
{% endfor %}
```

### A customer's own things

```twig
{% for license in craft.digits.myLicenses() %}
    <p>
        {{ license.downloads|map(d => d.title)|join(', ') }}
        {% if license.licenseKey %}<code>{{ license.licenseKey }}</code>{% endif %}
        {% if license.downloadsRemaining is not null %}
            — {{ license.downloadsRemaining }} downloads left
        {% endif %}
    </p>
{% endfor %}
```

`myLicenses()` finds both the licences the account owns and the ones still bearing only its email
address, so a guest purchase followed by registering does not look like a lost order.

## Releasing a new version

**Digits → Downloads → your download → New version.**

Save it as often as you like: a draft is not downloadable by anybody. **Release** is the separate
button, and it does three things — stamps the release date, makes it current, and (on Pro) queues
the notices.

The notices are queued because a download with four thousand licensees is four thousand emails.
Re-running the notifier is safe and is the intended repair: a second run only reaches the people
the first one missed.

### Updates after a licence lapses

With **Expired licences keep the versions they paid for** on — the default — a lapsed licence keeps
everything released while it was live and is turned away only from the builds that came out
afterwards. That is the "one year of updates" model, and the customer is told exactly that rather
than being shown an empty page.

Turn it off and expiry means what it says: nothing is downloadable.

## Support, when a customer says it does not work

**Digits → Licences**, search the email or the key. The edit screen shows the status, how much of
the allowance is gone, which machines it is on, and the last twenty-five things that happened to it
— including the refusals, with the reason.

Four buttons handle almost everything: **Resend email**, **Reset count**, **Extend a year**,
**Revoke**.

## Console

```sh
# Orders that completed without issuing a licence — a gateway timeout, or Digits installed late.
php craft digits/licenses/repair --dry-run
php craft digits/licenses/repair

# Every live version has files, and every file still has its bytes.
php craft digits/downloads/check
```

`downloads/check` is worth a cron entry. The failure it catches — an asset deleted from a volume,
leaving a file row pointing at nothing — is silent until a paying customer clicks the button.
