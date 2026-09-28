# Terminology

The words used in this documentation, in the code, and in the database.

| Term | Meaning here | Where it lives |
|---|---|---|
| Event | One raw security record from a source (IDS, firewall, system), with its network 5-tuple | `events` table |
| Alert | A qualified finding, with severity, status and optional link to an event and a device | `alerts` table |
| Incident | A case an analyst works on, with a lifecycle, an owner and a history | `incidents`, `incident_history` |
| Device | A network asset: router, switch, firewall, sensor, server, workstation, other | `devices` table |
| Severity | Integer 1 to 4 on events, alerts and incidents; 4 is critical | `severity` columns |
| Priority | Incident field: `low`, `medium`, `high`, `critical` (separate from severity) | `incidents.priority` |
| Session | Server-side PHP session, 6 hours absolute, identified by an HttpOnly cookie | `SessionManager` |
| Role | `admin`, `analyst` or `viewer`; decides what the API allows | `users.role` |
| Account status | `active`, `inactive` or `locked`; only `active` may sign in or keep a session | `users.status` |
| Audit log | Record of an authentication event: sign-in, failure, throttling, sign-out, revocation | `audit_logs` |
| Throttle bucket | Counter that slows repeated sign-in failures, keyed by a digest | `login_throttle` |
| Tenant / organization | Customer boundary in a multi-tenant product | **Not implemented** |
| Sensor | Probe that would send events to the platform | **Not implemented** |

A wider glossary of security terms (SIEM, SOC, IDS, IPS, RBAC…) is in
[16-reference/glossary.md](../16-reference/glossary.md).
