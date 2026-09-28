# SIEM and correlation

**Status: `PLANNED`.**

## Intended role

Aggregate events from several sources, correlate them over time, and raise alerts that deserve
an analyst's attention.

## What exists today

- `infrastructure/siem/configs/` and `infrastructure/siem/rules/` are empty.
- The application has no rule engine, no correlation window, no scheduler and no worker: all
  code runs inside a request.
- What exists is the storage the output would land in (`alerts`) and the case management that
  follows (`incidents`).

## Two possible shapes

| Option | Consequence |
|---|---|
| External SIEM writes alerts into the platform | Needs an ingestion endpoint and machine credentials; the platform stays a case-management tool |
| Correlation inside the platform | Needs a background worker and a rule model; a larger change to the current request-only design |

Neither has been decided. Whichever is chosen, the alert model already carries `source`,
`alert_type`, `signature`, `category`, `severity` and a link to the originating event.

## Where it would attach

See [../03-architecture/alert-flow.md](../03-architecture/alert-flow.md).
