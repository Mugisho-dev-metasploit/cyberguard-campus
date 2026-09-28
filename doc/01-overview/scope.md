# Scope

## In scope, and built

- Storage model for users, devices, events, alerts, incidents, incident history, audit logs and
  sign-in throttling — see [09-database/schema.md](../09-database/schema.md).
- REST API: sign-in, sign-out, read endpoints, and one write endpoint (incident update) — see
  [08-api/endpoints.md](../08-api/endpoints.md).
- Session lifecycle, roles and permissions — see [04-backend/authorization.md](../04-backend/authorization.md).
- Browser interface over the API, no build step — see [05-frontend/frontend-overview.md](../05-frontend/frontend-overview.md).
- Test suites, run by a single script — see [11-testing/testing-strategy.md](../11-testing/testing-strategy.md).

## In scope, not built

| Area | Note |
|---|---|
| Event ingestion | No write path for events or alerts, by API or otherwise (`PLANNED`) |
| Detection and correlation | No rules, no engine (`PLANNED`) |
| Integrations (Suricata, pfSense, Zabbix, Grafana, EVE-NG) | Empty directories only (`PLANNED`) |
| Notifications (mail, chat, webhooks) | No code (`PLANNED`) |
| Reporting and export | No code (`PLANNED`) |
| User administration (create, disable, reset password) | Accounts exist only in the database; no API, no interface (`PLANNED`) |
| Multi-tenancy | No organization entity (`PLANNED`) |

## Out of scope

- Replacing a firewall, an IDS/IPS or a packet analyser.
- Active response on network equipment (blocking, quarantining).
- Log storage at SIEM scale; the database holds operational records, not raw retention.

## Boundaries that the code enforces today

- Only `frontend/` and `backend/public/` are reachable over HTTP.
- Only an active account with a valid session reaches `/api/*`.
- Only `analyst` and `admin` may change an incident; only `admin` may close one.
