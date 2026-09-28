# Future commercialisation

**Status: `PROPOSED`.** An ordered view of what would have to exist before the product could be
sold, derived from the gaps the code shows.

## Stage 1 — make it deployable by someone else

| Item | Status |
|---|---|
| Documented, repeatable installation | `PARTIAL` — see [../10-deployment/development.md](../10-deployment/development.md) |
| HTTPS and production settings | `PLANNED` |
| Backups and a tested restore | `PLANNED` |
| User administration in the product | `PLANNED` |
| Packaging (container or installer) | `PLANNED` |

Without these, every deployment needs the author.

## Stage 2 — make it useful without manual SQL

| Item | Status |
|---|---|
| Event ingestion from at least one real source | `PLANNED` |
| Alert triage actions (acknowledge, dismiss, escalate to an incident) | `PLANNED` |
| Pagination, filtering and search | `PLANNED` |
| Notifications | `PLANNED` |

This is the difference between a case-management tool and a monitoring product.

## Stage 3 — make it multi-customer

Tenant model, isolation tests, onboarding, metering, and per-tenant retention — see
[../02-product/saas-model.md](../02-product/saas-model.md). This stage is a redesign of the data
model, not an addition.

## Stage 4 — commercial wrapper

Pricing, licensing, support commitments, security documentation for buyers (the material in
[../06-security](../06-security/security-overview.md) is a good starting point), and a
vulnerability-disclosure process.

## What already helps a commercial story

The audit trail, the hardened sign-in path and the test suites are the kind of evidence a buyer
asks for. Keeping the habit of "a finding, a fix, a test that proves it" is an asset.
