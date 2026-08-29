# Configuration

Digits' settings are five screens, because they are five subjects. Everything here is at
**Digits → Settings**.

## Downloads & delivery

| Setting | Default | What it does |
|---|---|---|
| Links last | 1440 min | How long a minted download link stays good. Long enough that somebody who opens the email on a train and downloads at home still gets the file; the *limit* is what actually protects it. |
| Downloads per licence | 0 | 0 is unlimited, which is right for most shops. |
| Access lasts | 0 days | How long a licence stays live. 0 is forever. |
| Bind links to the address | off | Leave it off unless the files are expensive: mobile networks change a visitor's IP mid-session, which turns "my download stopped working" into a support queue. |
| Always send as an attachment | on | Off lets a browser display a PDF inline. HTML and SVG are always sent as attachments whatever this says. |
| Hand files to the web server | PHP | `X-Accel-Redirect` (nginx) or `X-Sendfile` (Apache/LiteSpeed). Much faster for large files. |
| Internal paths | — | Maps a directory on disk to the internal location your server serves it from. |
| Keep log rows for | 365 days | 0 keeps them forever. |

Every download can override the first four. A blank box on a download means "follow the site
default"; a zero means "no limit", and the two are deliberately different.

### Handing files to the web server

Pushing a 2 GB file through PHP works and is slow. If you configure a handoff, you must also map
the paths, or Digits will not use it — a silently emitted `X-Accel-Redirect` that the server does
not understand sends the customer an empty `200`, which looks like success and is the worst
possible failure for a download. If no path matches, Digits quietly serves through PHP instead.

An nginx location to serve from, for a volume at `/var/www/html/storage/protected`:

```nginx
location /protected/ {
    internal;
    alias /var/www/html/storage/protected/;
}
```

…and the mapping: `/var/www/html/storage/protected` → `/protected`.

## Licences

| Setting | Default | What it does |
|---|---|---|
| Key format | `XXXX-XXXX-XXXX-XXXX` | Every `X` becomes a random character; everything else is literal. |
| Alphabet | `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` | No `I`, `O`, `0` or `1` — a licence key is a thing people read aloud down a telephone. |
| Prefix | — | Prepended to every generated key. |
| Issue a licence | on completion | Or on payment, which still creates the licence at completion, as **pending**. |
| Activations per licence | 1 | 0 is unlimited. |
| Buying three gives three keys | off | Off, three of a one-machine licence is one key good for three machines — which is what "three of these" from one company usually means. |
| Email when downloads are ready | on | |
| Email about new versions | on | |
| Serve the activation API | on | |
| When money goes back | full refund | Or any refund, or never. |
| Statuses that revoke | cancelled | Applying one withdraws everything the order granted. |

### Why "waiting for payment" still issues a licence

A customer paying by bank transfer who signs in and sees an empty account page emails support. One
who sees "waiting for payment" does not. So the pending licence exists, is listed, and refuses to
download until the money lands.

### What a partial refund can and cannot do

Commerce's refund transactions are not line-scoped: a partial refund knows an amount, not which
product it was for. Digits therefore offers the two answers it can defend — revoke everything on a
**full** refund, or revoke on **any** refund — and does not guess in between.

## Customer portal (Pro)

| Setting | Default |
|---|---|
| Serve the portal | on |
| Path | `account/downloads` |
| Guest link lasts | 10080 min (7 days) |
| Let people ask for a fresh link | on |

The "email me a link" form answers identically whether or not the address has ever bought
anything. Otherwise it becomes a way for a stranger to ask your shop whether somebody is a
customer.

## Invoicing (Pro)

See [EU VAT and invoicing](vat.md).

## Fields

Two field layouts: one for what a **download** is about (description, artwork, requirements) and
one for what your team records about a **licence**. What Digits itself has to reason about — who
may have it, how many times, for how long — is on the element and cannot be removed.

## Config file

Everything above can be overridden per environment in `config/digits.php`, the same as any Craft
plugin:

```php
<?php

return [
    'linkTtl' => 60,
    'fileDeliveryMethod' => 'x-accel-redirect',
    'internalPaths' => [
        ['path' => '/var/www/html/storage/protected', 'location' => '/protected'],
    ],
    'sellerCountry' => 'DE',
    'validateVatIds' => true,
];
```
