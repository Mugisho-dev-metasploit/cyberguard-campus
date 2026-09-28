# Target customers

**Status: `PROPOSED`** — the code contains no customer model. The reasoning below comes from
what the product does, not from market research (none exists in the repository).

## Fit

| Profile | Why it could fit | What would be needed |
|---|---|---|
| University or campus network (the original target) | Mixed population, a modest security team, an existing IDS or firewall | Ingestion from those tools |
| Small or medium organisation with an IP network | Needs case management more than another console | Ingestion, plus simple installation |
| Security team using open-source sensors | The data model already matches Suricata-style records | Collectors |
| Teaching or laboratory environment | The application is self-contained and readable; the security work is documented ticket by ticket | Nothing — this works today |

## Poor fit today

| Profile | Why |
|---|---|
| Organisation needing multi-tenant isolation | No tenant boundary |
| High-volume environments | Full-table reads, no pagination, no retention |
| Regulated environments requiring strong auditability of every read | Only authentication and incident changes are audited |
| Teams expecting turnkey integrations | None exists |

## The one profile the product serves today, end to end

A single team, on one network, that already has security records in a database and needs to
triage them and run incidents with a clear trail.
