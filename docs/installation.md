---
title: Installation
slug: installation
order: 10
summary: Requirements, install, the Lite and Pro editions, and your first gated download.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- Craft Commerce 5.0 or later — **only** if you want to sell downloads or issue VAT invoices

## Installing

```sh
composer require justinholtweb/craft-digits
php craft plugin/install digits
```

Installing creates thirteen tables and seeds the 27 EU standard VAT rates with an effective date.
It creates no downloads and no licences: nothing on your site changes until you make one.

## Editions

**Lite is free** and is a working download shop: selling files, expiring links, download limits,
versions and refund revocation, uncapped. **Pro is $129, with a $99/year renewal for updates**, and
adds licence keys, activation seats, the customer portal, release notices, update gating and the VAT
paperwork.

## Switching to Pro

Settings → Plugins → Digits, or:

```sh
php craft plugin/switch-edition digits pro
```

The edition lives in project config, so it travels with a deployment like everything else there.

## Wiring it up

Digits attaches downloads to things through a single relation field.

1. **Create a Downloads field.** Settings → Fields → New field → Downloads.
2. **Put it where you sell.** For Commerce, add it to a variant's (or product's) field layout. For
   gated content with no Commerce, add it to an entry's.
3. **Create a download.** Digits → Downloads → New download. Give it a title, decide who may have
   it, and add a version with at least one file.
4. **Relate them.** Edit the variant or entry and choose the download.

The settings screen tells you if no Downloads field exists anywhere yet, which is the mistake that
otherwise shows up as "no licences are appearing".

### The SKU fallback

A download can carry a SKU, which Digits matches against a Commerce line item when nothing relates
it through a field. Use it when the product field layout belongs to somebody else, or when your
catalogue is imported by a script that knows about SKUs and nothing else.

Relations win. A download related through the field is used, and a matching SKU is not consulted —
so a stray SKU cannot quietly add a fourth file to an order.

## Where things live

- The catalogue and its releases: **Digits → Downloads**
- Who has what: **Digits → Licences**
- What happened: **Digits → Activity**
- VAT documents: **Digits → Invoices** (Pro, once invoicing is switched on)
- Everything else: **Digits → Settings**

## The customer's account page

Pro serves a front-end portal at `account/downloads` by default. Change the path under
Settings → Customer portal.

To restyle it, copy any of the plugin's `src/templates/_portal/*.twig` into your own
`templates/digits/portal/` — Digits looks for the site's copy first, so changing the account page
never means forking the plugin.

## Uninstalling

```sh
php craft plugin/uninstall digits
```

This drops every Digits table. Licences, activation history and invoices go with them, so take a
database backup first if any of it matters — and it usually does, because an invoice is a document
somebody may have to produce years later.

Your files are assets and are untouched.
