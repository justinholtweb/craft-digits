---
title: FAQ
slug: faq
order: 70
summary: Commerce, digital-products, signed URLs, refunds and VAT — the questions worth answering first.
---

### What does it cost?

Lite is free, with no cap on downloads or buyers. Pro is $129 with a $99/year renewal, and adds
licence keys, activation seats, the portal, release notices, update gating and VAT invoicing.

### Do I need Commerce?

No. Gating a file behind a user group, issuing a licence by hand, expiring a link and counting a
delivery are Craft-only features. Commerce is needed to *sell* a download and to raise VAT
invoices, and nothing else.

### How is this different from `craftcms/digital-products`?

That plugin gives Commerce a digital purchasable. Digits is everything after the sale: links that
expire, downloads that are counted, files that have versions, keys that software can check, an
account page, refunds that take the files back, and VAT paperwork.

They can coexist, but they overlap: if you use both, sell through one of them.

### Why is a download link a database row rather than a signed URL?

Because a signed URL cannot be taken back. Refunds have to revoke, and the link that was emailed on
Tuesday has to stop working on Thursday. Only a row can do that. It also lets Digits count
redemptions and tell you, weeks later, whether a particular link was used and from where.

The row stores the **hash** of the token, never the token, so a leaked backup is a list of hashes.

### Does a resumed download spend two of my customer's allowance?

No. A download manager fetching a large file in eight pieces sends eight requests for one download;
only a request that is not asking for a continuation counts. A fresh `Range: bytes=0-` counts;
`Range: bytes=5000000-` does not.

### What happens when a licence expires?

By default, the customer keeps everything released while it was live and is turned away only from
the builds that came out afterwards — and is told exactly that, with the earlier versions still
downloadable. Turn off **Expired licences keep the versions they paid for** on a download and
expiry means nothing is downloadable.

### Someone bought three copies. Do they get three keys?

By default, one key good for three machines — which is what "three of these" from one company
usually means. Turn on **Buying three gives three keys** under Settings → Licences if you sell
gift copies.

### A customer reinstalled and now says they are out of seats.

They should not be: an activation is keyed on the instance, and the same instance activating twice
is one seat. If the instance string changed — a new hostname, a regenerated machine id — it is
genuinely a different seat. Remove the old one from the licence screen, or from their own account
page.

### Can a guest who checked out without an account get to their files?

Yes. They are emailed a link, and the "email me a link" form on the account page sends a fresh one.
When they later register with the same address, the licences are attached to the account
automatically.

### Why does the "email me a link" form say the same thing whether or not I am a customer?

Because otherwise it is a way for a stranger to ask your shop whether a given person has bought
from you.

### Can I restyle the account page?

Copy any of `src/templates/_portal/*.twig` into your own `templates/digits/portal/`. Digits looks
for the site's copy first, so it never means forking the plugin.

### Where do very large files go?

Ideally an asset on a volume outside the web root — that is the case Digits can actually protect,
serving it a request at a time with range support. For the 4 GB ISO nobody wants inside Craft's
volumes, a file can be a URL instead: entitlement is still checked and the download is still
counted, and Digits is honest that what is behind the URL is only as private as the URL.

### Will Digits charge the right VAT?

Digits does not charge VAT at all — see [EU VAT and invoicing](https://justinholt.com/plugins/craft-digits/docs/vat). It determines the correct
treatment, prints it, records what Commerce actually took, and flags the invoices where the two
disagree.

### Do I need VIES?

Only if you want to zero-rate B2B sales. Without it, Digits still checks the shape of a VAT number
— which catches typos — but will not claim the reverse charge on that alone, because a format check
is not evidence that a trader exists.

### What does Pro actually buy?

The software vendor's half: licence keys, the activation API, seats, the customer portal, release
notices, update gating, and VAT invoicing. Lite is a working download shop with no cap on downloads
or buyers.

### Does Digits make outbound requests?

Only one, only when you ask for it: the VIES check, when **Check VAT numbers against VIES** is on.
Nothing else in the plugin talks to anything.
