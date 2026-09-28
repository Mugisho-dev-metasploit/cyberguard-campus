# Roadmap

Four separate lists. Nothing moves from one to another without code in the repository.

## Implemented

- Authentication: sign-in, sign-out, 6-hour absolute sessions, revocation on account change.
- Sign-in hardening: throttling, timing equalisation, CSRF protection, input bounds, no secrets
  in traces, cache headers, session establishment checks, password hash upgrades.
- RBAC: `viewer`, `analyst`, `admin` enforced by middleware on every route.
- Incident lifecycle with a server-side state machine, history and lifecycle timestamps.
- Read API for incidents, events, alerts, devices and metrics.
- Web interface: welcome, sign-in, dashboard, events, alerts, incidents, devices, monitoring.
- Authentication audit trail in `audit_logs`.
- 21 test suites covering behaviour, concurrency and security.
- Web exposure policy: only the interface and the API front controller are served.

## In progress

Nothing is half-built in the repository at the analysed commit. The closest thing to an
in-progress area is documentation: `docs/` contains one security document and empty
directories.

## Planned

| Item | What it needs |
|---|---|
| Event ingestion | A write path (authenticated API or collector) plus normalisation |
| Sensors and integrations | Suricata, pfSense, Zabbix, Grafana, EVE-NG — the directories exist, the content does not |
| Detection and correlation | Rules, an engine, and a way to raise alerts from events |
| User administration | API and interface to create, disable and reset accounts |
| HTTPS and production deployment | See [10-deployment/production.md](../10-deployment/production.md) |
| Pagination and filtering on read endpoints | Today every read endpoint returns the whole table |
| Reporting and export | No code today |

## Proposed

Ideas with no decision and no code: multi-tenancy (`PROPOSED`, see
[02-product/saas-model.md](../02-product/saas-model.md)), automation with n8n, assistance
features based on a language model, notification channels, and a mobile client. None of these
appear anywhere in the repository.
