# Zabbix

**Status: `PLANNED`.**

## Intended role

Availability and performance monitoring for the assets held in the device inventory: is the host
up, how does it behave, when was it last seen.

## What exists today

- `infrastructure/zabbix/configs/` and `infrastructure/zabbix/templates/` are empty.
- Nothing in the application reads from or writes to Zabbix.
- `devices.status` (`online`, `offline`, `degraded`, `unknown`) and `devices.last_seen_at` are
  recorded values; the Devices page deliberately shows the recorded status **against** the age
  of the last observation, because nothing verifies it.

## What integrating it would require

| Need | Detail |
|---|---|
| Direction | Zabbix pushes to the platform, or the platform polls Zabbix |
| Identity mapping | Which Zabbix host corresponds to which `devices` row |
| Fields | What updates `status` and `last_seen_at`, and how often |
| Failure handling | A monitoring outage must not make every device look offline |

## Where it would attach

The device inventory; see [../05-frontend/pages.md](../05-frontend/pages.md) for how staleness
is presented today.
