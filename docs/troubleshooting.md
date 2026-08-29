# Troubleshooting

## Nothing happens when somebody buys

**No Downloads field anywhere.** The settings screen says so at the top of
Digits → Settings → Downloads if this is the problem. Add the field to your variant's field layout
and relate the download.

**The download does not issue a licence.** Check **Buying this issues a licence** on the download —
it is off for files that come free with something else.

**The order completed before Digits was installed**, or a gateway timeout swallowed the completion
event:

```sh
php craft digits/licenses/repair --dry-run
```

That prints every completed order missing a licence. Without `--dry-run` it issues them. It is
idempotent: a line item that already has a licence is skipped.

## The customer says the download does not work

**Digits → Licences**, search their email or key. The screen shows the status, the allowance, the
seats and the last twenty-five events — including refusals, with a reason.

The reason codes:

| Reason | What happened |
|---|---|
| `no-licence` | They do not have one for this download. |
| `expired` | It lapsed. With update gating on, older versions still work. |
| `update-gated` | The build came out after their access ended. |
| `revoked` | Withdrawn — usually a refund. |
| `limit-reached` | The download allowance is spent. **Reset count** fixes it. |
| `pending` | Waiting for payment. |
| `link-expired` / `link-already-used` | The link is old. Resend the email. |
| `file-missing` | The asset behind the file was deleted. |
| `download-disabled` | The download element is disabled. |

**Resend email** is the button that solves most of these.

## An empty file, or a `200` with no content

You configured **Hand files to the web server** and the internal paths do not match, or the server
is not configured to serve them. Digits falls back to PHP when no path matches, so an empty
response means the header *was* emitted and the server did not understand it.

Set the delivery method back to PHP, confirm downloads work, then add the mapping and the `internal`
location together.

## A file that used to work is now "not available"

The asset behind it was deleted or moved. The file row survives on purpose — an empty row is a
fixable mistake, a vanished one is a mystery — so open the version and point it at the file again.

Worth a cron entry to find these before a customer does:

```sh
php craft digits/downloads/check
```

## Release notices did not go out

They are queued. Check the queue.

To send them again — safely, to only the people the first run missed:

```sh
php craft digits/downloads/notify <versionId>
```

The notice rows carry a unique index on (version, licence), so nobody is emailed twice.

Also check that **Email licence holders about new versions** is on for both the site and the
download, and that release notices are a Pro feature.

## Activation always answers `disabled`

Either the plugin is on Lite, or **Serve the activation API** is off under
Digits → Settings → Licences.

## Activation answers `seat-limit` for one machine

The instance string is changing between calls. Send something stable — a site URL rather than a
request host that varies, or a stored machine id. Digits normalises URLs (scheme, `www.`, trailing
slash, case) but cannot normalise a value that is genuinely different each time.

`php craft digits/licenses/recount` puts a drifted count right if you have been editing rows
directly.

## Too many licence requests

The API allows 30 requests a minute per address. An updater checking on every page load of a busy
site will not hit that; a loop will. Cache the answer in your customer's software.

## Duplicate invoice number

Numbers are unique across the install, not per series. If you run more than one series, put
`{series}` in the invoice number format. Digits will bump past a collision rather than raising a
duplicate, so you will see a gap in one series rather than an error — the format is still the thing
to fix.

## An invoice's VAT does not match what was charged

That is the flag doing its job, and it is usually right that the two differ: a tax rule in Commerce
and the place-of-supply rules disagree. Digits does not silently restate the charge — see
[EU VAT and invoicing](vat.md). Look at the invoice's detail screen, which shows both figures and
the evidence behind the treatment.

## The VAT screen says the rates were seeded a long time ago

They were, and rates change by legislation. Add a new row with an effective date rather than
editing the old one, so invoices raised under the old rate keep it.

## VIES checks are slow, or the checkout hangs

Turn **Check VAT numbers against VIES** off, or lower the timeout. Digits falls back to the offline
format check rather than throwing, but a five-second wait is still five seconds. An answer is
cached for 90 days.

## The portal is a 404

It is Pro, and **Serve the portal** has to be on. Check the path under
Digits → Settings → Customer portal, and remember it is a site URL rather than a control-panel one.

## Uninstalling took the invoices with it

It did — uninstalling drops every Digits table. Restore the backup you took first. If you did not
take one, the invoices are gone; this is why the uninstall documentation says to take one.
