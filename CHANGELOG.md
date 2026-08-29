# Release Notes for Digits

## 5.0.0

Initial release.

### Downloads

- Download elements with a field layout, an element index and the trash
- Access by licence, by user group, by sign-in, or public
- Expiring download links, minted per click and stored as rows so they can be revoked
- Download limits enforced by a conditional update, so two simultaneous requests cannot share the
  last one
- Range requests, remote filesystems, `X-Sendfile` and `X-Accel-Redirect` handled properly
- An append-only activity log that records refusals as well as deliveries

### Versions

- Several files per release; assets or external URLs
- Draft and released versions, with exactly one current version per download
- Release dates that "your licence covers updates until…" is measured against
- Release notices to licence holders, queued and safe to re-run

### Licences

- Licences as the single entitlement — no separate purchase or grant concept
- Keys in a configurable format, drawn from an alphabet without `I`, `O`, `0` or `1`
- Activation seats, with a reinstall counted as the same seat
- An activation API for a customer's software: activate, deactivate, check, versions
- Revoking a licence deletes the download links already in the customer's inbox
- Guest licences claimed by an account when its email matches

### Commerce

- One relation field attaches downloads to any purchasable, with SKU matching as a fallback
- Licences issued on completion or held pending until payment
- Refunds revoke, on a full refund or on any refund, or never
- Order statuses that withdraw access
- A panel on the order's own edit screen

### Portal

- A front-end account page for signed-in customers and for guests, by emailed link
- Files, versions, keys, seats and invoices, with the templates overridable in the site

### VAT

- Dated VAT rates for the 27 EU member states, seeded and editable
- Place-of-supply treatment: domestic, the customer's country, reverse charge, outside the scope
- VAT ID checking — offline by default, VIES when asked for, and a record of which it was
- Sequential, gapless invoice numbering with a per-period counter under a lock
- Snapshot invoices and credit notes; nothing edits an issued document
- The quarterly OSS return, on screen and as a CSV
