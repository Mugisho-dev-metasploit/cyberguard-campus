# Firewall integration (pfSense)

**Status: `PLANNED`.**

## Intended role

A firewall would provide blocked and allowed flows, rule hits and state changes — the context
that makes an IDS alert actionable.

## What exists today

- `infrastructure/pfsense/configs/` and `infrastructure/pfsense/backups/` are empty.
- No syslog receiver, no API client, no parser anywhere in the application.
- A firewall can be recorded in the inventory (`devices.device_type = 'firewall'`), nothing more.

## What integrating it would require

| Need | Detail |
|---|---|
| Transport | Syslog receiver or a log shipper writing into the platform |
| Normalisation | Firewall log lines → `events` columns |
| Volume decision | Firewall logs dwarf every other source; filtering and retention must be designed first |
| Correlation | Matching a firewall flow with an IDS alert on the 5-tuple and a time window |

## Where it would attach

See [../03-architecture/event-flow.md](../03-architecture/event-flow.md) and
[siem.md](siem.md).
