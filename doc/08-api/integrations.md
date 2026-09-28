# API integrations

**Status: `PLANNED`.** No third-party system is integrated. See
[03-architecture/integration-architecture.md](../03-architecture/integration-architecture.md)
for the architecture view and
[07-network-monitoring/monitoring-overview.md](../07-network-monitoring/monitoring-overview.md)
for the per-tool status.

## Using the API from another program today

It is possible, with the same constraints as a browser:

| Constraint | Consequence |
|---|---|
| Session cookies only | The client must sign in as a real user and keep a cookie jar |
| `Content-Type: application/json` on `/login` | A form-encoded sign-in is refused (415) |
| `Origin` checked when sent | A server-side client that sends no `Origin` is accepted |
| 6-hour absolute session | The client must sign in again afterwards |
| Sign-in throttling | Repeated failures are delayed; a healthy client is unaffected |
| No pagination | A large table is returned in one response |

This is workable for a script, but it means sharing a human account, which no audit trail can
untangle. A machine credential model is the missing piece (`PLANNED`).

## Grafana, n8n, SIEM

Nothing in the repository connects to them. A Grafana dashboard could read the MariaDB database
directly with a read-only account — that is a database integration, not an API one, and it is
not configured anywhere (`PROPOSED`).
