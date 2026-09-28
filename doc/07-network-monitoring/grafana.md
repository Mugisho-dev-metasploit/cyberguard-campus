# Grafana

**Status: `PLANNED`.**

## Intended role

Dashboards and trends beyond the views built into the application.

## What exists today

- `infrastructure/grafana/configs/` and `infrastructure/grafana/dashboards/` are empty.
- The application ships its own charts (`frontend/assets/js/charts.js`) and exposes no metrics
  endpoint for an external tool: `/api/metrics` returns five counters and requires a user
  session.

## What integrating it would require

| Option | Detail |
|---|---|
| Database data source | A dedicated **read-only** MariaDB account for Grafana, plus dashboards. No application change; the simplest path |
| Metrics endpoint | Would need machine authentication, which does not exist |

If the database option is chosen, the account must be separate from the application account and
limited to `SELECT` on the tables the dashboards need.

## Where it would attach

Outside the application; see [../06-security/database-security.md](../06-security/database-security.md)
for the privilege model to respect.
