# Business model

**Status: `PROPOSED`. Nothing commercial exists in the repository**: no billing, no plans, no
tenancy, no usage metering, no pricing document. This page frames the possibilities without
inventing figures.

## What the product could be sold as

| Model | Fit with the current code | Missing |
|---|---|---|
| Self-hosted, one installation per organisation | Closest to today: one database, one configuration | Packaging, installer, upgrade path, support process |
| Managed hosting, one instance per customer | Same code, operated by the vendor | Deployment automation, backups, monitoring, per-instance isolation |
| Multi-tenant SaaS | Furthest away | Tenant model, isolation, onboarding, metering, billing — see [../02-product/saas-model.md](../02-product/saas-model.md) |

The honest statement today: the application is a **single-organisation tool**, deployable by
someone who administers the stack.

## Cost drivers if it were operated

- Storage growth, driven by events once ingestion exists — nothing purges data today.
- The bcrypt cost per sign-in (~235 ms of CPU), which is a deliberate security trade-off.
- Operational work that is currently manual: accounts, backups, upgrades.

## Not documented because it does not exist

Pricing, licensing terms, service levels, customer count, revenue, or any market figure. None
of it can be derived from the repository.
