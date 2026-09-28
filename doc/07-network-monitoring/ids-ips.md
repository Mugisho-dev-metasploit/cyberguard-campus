# IDS / IPS (Suricata)

**Status: `PLANNED`.**

## Intended role

Suricata would inspect mirrored traffic and produce alerts, typically as EVE JSON, which the
platform would turn into events and alerts.

## What exists today

- `infrastructure/suricata/configs/` and `infrastructure/suricata/rules/` are empty.
- No rule file, no EVE parser, no ingestion path in the application.
- The `events` table already models what Suricata emits: `source`, `event_type`, `severity`,
  `signature`, `category`, the 5-tuple, and a `raw_data` column for the original record.

## What integrating it would require

| Need | Detail |
|---|---|
| Reader | Tail the EVE JSON file, or read from a socket or a shipper |
| Mapping | Suricata fields → `events` columns; keep the original in `raw_data` |
| Severity scale | Suricata priorities mapped to the platform's 1–4 |
| Promotion rule | Which events become alerts (`alerts.event_id` links them) |
| Volume control | Filtering and retention before storage, not after |

## Where it would attach

See [../03-architecture/event-flow.md](../03-architecture/event-flow.md).
