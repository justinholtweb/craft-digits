# Licensing and the activation API

*Licence keys, seats and the API are Digits Pro. Lite still issues a licence for every purchase —
that licence is what makes a download link work — it just has no key for software to check.*

## What a key is, and is not

A licence key is the customer's copy of an entitlement, not a password. It is checked against the
database on every call, so it does not need to be cryptographically secret. It needs two other
things:

- **Unguessable enough that nobody enumerates the space.** Characters come from `random_int()`.
- **Transcribable by a person reading it down a telephone.** Which is why the default alphabet has
  no `I`, `O`, `0` or `1` in it, and why Digits is generous about what it accepts back: somebody who
  types `l` for `1`, lower-cases the lot and leaves the dashes out still gets in.

## Seats

An activation is keyed on an **instance** — whatever the customer's software calls itself: a site
URL, a machine fingerprint, a container id. Digits has no opinion about the format and normalises
it once (case, `www.`, trailing slash), so `https://Example.com/` and `example.com` are one seat
rather than two.

The same installation activating twice is **one seat**. A customer who reinstalls, restores a
backup, or clones staging from production does not silently burn through their allowance.

Deactivating frees the seat and keeps the row. A vendor asking "has this customer ever run it on
that server" has a right to an answer.

## The API

Four endpoints, all JSON, all `POST`.

```
/digits/api/activate      key, instance, label?
/digits/api/deactivate    key, instance
/digits/api/check         key, instance?
/digits/api/versions      key, download?
```

Every response is the same envelope:

```json
{
  "success": false,
  "code": "seat-limit",
  "message": "That licence is already in use on all 3 of its activations. Deactivate one first.",
  "license": { "key": "…", "status": "active", "expiryDate": "…", "activationLimit": 3, "activationCount": 3 }
}
```

The `code` is the part to branch on, because these lead to different dialogues and only one of them
should ever nag the customer:

| Code | Means |
|---|---|
| `ok` | Done. |
| `unknown-key` | The key was not recognised. |
| `expired` | The licence lapsed. `license.expiryDate` says when. |
| `revoked` | Withdrawn — usually a refund. |
| `seat-limit` | All seats are in use. Deactivate one. |
| `not-activated` | The licence is fine; this instance is not activated. |
| `not-active` | Pending payment, or disabled. |
| `disabled` | Licence activation is switched off on the shop. |
| `no-instance` | No site or machine was given. |

Failures answer `422`, successes `200`.

### Checking for updates

`versions` returns only the releases the licence is actually entitled to, each file with a freshly
minted download URL:

```json
{
  "success": true,
  "downloads": [{
    "id": 41, "sku": "TOOLKIT", "title": "Toolkit", "current": "2.1.0",
    "versions": [{
      "version": "2.1.0", "dateReleased": "2026-08-01T09:00:00+00:00",
      "releaseNotes": "…",
      "files": [{ "name": "macOS", "filename": "toolkit-2.1.0.dmg", "size": 88410112, "url": "https://…" }]
    }]
  }]
}
```

A lapsed customer is told about the build they own rather than offered one they would then be
refused — the update check and the download went through the same access service.

Those URLs are short-lived by design. An updater that stores one and reuses it next month gets a
`410`, which is correct: the entitlement was checked when the link was made, and a link that
outlived its check is a link that outlived a refund.

### Not CSRF-protected, on purpose

These requests come from software on somebody else's machine, which has no session and no token to
send. The credential is the key. What replaces CSRF is a per-address budget of 30 requests a minute
— generous enough that an updater checking on every page load of a busy site is fine, and present
because a key is a short string.

### A worked example

```php
$response = file_get_contents('https://shop.example.com/digits/api/activate', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'key' => $key,
            'instance' => 'https://' . $_SERVER['HTTP_HOST'],
            'label' => 'Production',
        ]),
        'ignore_errors' => true,
    ],
]));

$result = json_decode($response, true);

if (!$result['success']) {
    // Show $result['message'], and branch on $result['code'] for anything you handle specially.
}
```

## Revoking

Revoking a licence is a **status**, never a delete. A refunded customer's licence is evidence — of
what they had, of when it stopped, and of why — and the chargeback conversation six weeks later
goes badly without it.

Revoking also deletes every unspent download link belonging to that licence. That is the entire
reason a link is a row and not a signed URL: a refund that leaves a working link in somebody's
inbox has not revoked anything.

## Issuing by hand

**Digits → Licences → New licence**, or:

```sh
php craft digits/licenses/issue --email=a@b.com --downloads=toolkit,manual --days=365 --seats=3
```

`--downloads` takes IDs or SKUs. Leave `--days` and `--seats` out to follow what the downloads
themselves say.
