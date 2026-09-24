---
title: EU VAT and invoicing
slug: vat
order: 50
summary: Place-of-supply treatment, VAT ID evidence, snapshot invoices, credit notes and the OSS return.
---

*Digits Pro, and off until you switch it on.*

## What this does, and what it deliberately does not

Digits **determines and documents** VAT. It does not charge it.

Commerce's tax engine decides what a customer pays. Quietly restating that number on the invoice is
how a shop ends up with books that do not reconcile and an accountant who trusts neither figure.

So Digits works out what the treatment *should* be under the place-of-supply rules, prints it with
the wording the directive requires, records what Commerce actually took beside it, and puts the
invoices where the two disagree on one screen for somebody to look at.

If you need Commerce to charge a different rate, that is a tax rule in Commerce. Digits will tell
you that you need one.

## The four treatments

Electronically supplied services — which a digital download is:

| Buyer | VAT number | Treatment | Rate |
|---|---|---|---|
| Your own country | — | **Domestic** | your country's |
| Elsewhere in the EU | none, or unconfirmed | **Customer's country** (OSS) | the customer's |
| Elsewhere in the EU | confirmed | **Reverse charge** | 0%, with the required wording |
| Outside the EU | — | **Outside the scope** | 0% |

## Why a VAT number's *source* is recorded

A number that passes a format check is a number that is not a typo.

A number VIES confirmed is a number the European Commission says belongs to a registered trader
today.

Only the second is evidence for zero-rating a sale, and an invoice that does not record which of
the two it had cannot be defended three years later. Digits stores both, prints the difference, and
**will not claim the reverse charge on a format check alone** — a human has to confirm the number,
or VIES has to.

### VIES is opt-in

Checking against VIES is an outbound request to the Commission's service during somebody's
checkout, and that service is frequently down. So it is off by default, the timeout is short, and a
failure falls back to the format answer rather than throwing — a customer must not be unable to buy
because Brussels is having an afternoon.

An answer is cached for 90 days by default. A number confirmed by hand never goes stale, because
somebody looked at it on purpose.

Where the number comes from: `organizationTaxId` on the billing address — Craft's own field for
exactly this, so you get a VAT box in your checkout by enabling an address field rather than by
installing anything. A custom `vatId` field on the address is honoured too.

## Evidence

The place-of-supply rules want **two non-contradictory pieces** of evidence for where a consumer is.
Digits can offer the billing country and, where your CDN sets a country header, the country the
request came from.

It will not do a geo-IP lookup of its own — that is an outbound request on every checkout and a
database to keep current. When it has only one piece, the invoice **says** it had one piece rather
than implying otherwise, and when the two disagree the invoice is flagged.

Set the header name under Settings → Invoicing. Cloudflare's is `CF-IPCountry`.

## Rates

Installing seeds the 27 EU standard rates with an effective date, and the VAT screen tells you when
they were seeded. **Confirm them before your first return** — they change by legislation, not by
plugin release.

Rates are **dated, not current**. Add a row with a start date rather than editing the old one: an
invoice raised in March has to keep March's rate after April's takes effect, including when it is
reprinted next year for an auditor.

## Numbering

An invoice number has to be **sequential and gapless within its series**, which is a stronger
promise than unique. Digits allocates from a counter row, under a named lock, at the moment the
document is written.

The counter is bucketed by reset period — never, yearly or monthly — so "restart every year"
means something.

Numbers are unique across the whole install, not merely within a series, because that is what an
invoice number has to be. If you run more than one series, put `{series}` in the format; if you
forget, Digits bumps the counter past the collision rather than raising a duplicate.

Set **Start at** to carry on from the numbers you were already using.

## Snapshots, and why nothing edits an invoice

Everything printed on an invoice is copied on to it at issue time — the addresses, the lines, every
figure. The order will go on changing afterwards, and none of that may reach a document somebody
has already filed with their return.

A mistake is corrected with a **credit note**: its own number in the same series, every figure
negated, and a pointer back to what it corrects. That is what the rules require and the only
version of events that survives an audit. Nothing in Digits edits or deletes an issued invoice.

Partial credits are proportional — credit €60 of a €120 invoice and the VAT is halved with it.

## The OSS return

**Digits → Invoices → VAT return** defaults to the quarter that has just ended, because the only
reason anybody opens that screen is that a return is due.

One row per country and rate, which is the shape the return asks for, downloadable as a CSV.
Reverse charges and out-of-scope sales are **excluded** from the totals and listed separately
below: they belong on the return as a different figure, and leaving them in overstates the
liability.

From the console, for a cron job:

```sh
php craft digits/invoices/report 2026-04-01 2026-07-01
```

## Turning it on for a shop that has been selling for a while

```sh
php craft digits/invoices/backfill --dry-run
php craft digits/invoices/backfill 2026-01-01
```

`--dry-run` prints the treatment, rate and net for every order that would get a document, without
writing anything. Run it first: it is also the fastest way to find out that your seller country is
not set.

## What Digits does not do

- It does not calculate or collect tax at checkout.
- It does not file anything with anybody.
- It does not handle the Import One-Stop Shop, distance selling of goods, or thresholds — Digits
  sells files.
- It has no opinion about whether your shop is registered where it should be.
