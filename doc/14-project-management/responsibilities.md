# Responsibilities

**Status: `UNKNOWN` for people, `IMPLEMENTED` for components.**

The repository does not assign owners. What can be documented is which component covers which
responsibility, so ownership can be attached later.

| Area | Components | Documentation |
|---|---|---|
| API and business rules | `backend/src/Controllers`, `Services`, `Repositories` | [04-backend](../04-backend/backend-overview.md) |
| Authentication and sessions | `AuthenticationService`, `SessionManager`, `LoginThrottle`, middleware | [06-security/authentication-security.md](../06-security/authentication-security.md) |
| Incident lifecycle | `IncidentService`, `IncidentRepository`, `incident_history` | [03-architecture/incident-flow.md](../03-architecture/incident-flow.md) |
| Web interface | `frontend/` | [05-frontend](../05-frontend/frontend-overview.md) |
| Database schema | `backend/database/migrations` | [09-database](../09-database/schema.md) |
| Tests | `backend/tests` | [11-testing](../11-testing/testing-strategy.md) |
| Web exposure and host configuration | `.htaccess` files, Apache, PHP settings | [06-security/network-security.md](../06-security/network-security.md) |
| Infrastructure integrations | `infrastructure/` (empty) | [07-network-monitoring](../07-network-monitoring/monitoring-overview.md) |

## Operational responsibilities with no owner recorded

- Account administration (creating users, changing roles) — done in SQL, unaudited.
- Database backup — does not exist.
- Certificate and HTTPS management — not deployed.
- Log rotation and permissions on the host.
