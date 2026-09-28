# Objectives

Objectives that can be checked against the repository. Each one links to the evidence.

## Achieved

| Objective | Evidence | Status |
|---|---|---|
| A REST API with a clean layering (controller → service → repository → database) | `backend/src` | `IMPLEMENTED` |
| Authentication with sessions and role-based access control | [06-security/authentication-security.md](../06-security/authentication-security.md) | `IMPLEMENTED` |
| An incident lifecycle enforced by the server, with history | [03-architecture/incident-flow.md](../03-architecture/incident-flow.md) | `IMPLEMENTED` |
| A security operations interface over the API | [05-frontend/pages.md](../05-frontend/pages.md) | `IMPLEMENTED` |
| Hardened sign-in (brute force, timing, CSRF, input bounds, session integrity) | [06-security/security-overview.md](../06-security/security-overview.md) | `IMPLEMENTED` |
| Authentication events recorded in an audit trail | [06-security/logging-auditing.md](../06-security/logging-auditing.md) | `IMPLEMENTED` |
| Automated tests covering behaviour and security | [11-testing/testing-strategy.md](../11-testing/testing-strategy.md) | `IMPLEMENTED` |
| Nothing but the interface and the API reachable over HTTP | [06-security/network-security.md](../06-security/network-security.md) | `IMPLEMENTED` |

## Not achieved yet

| Objective | Missing | Status |
|---|---|---|
| Collect events from the network | No collector, no ingestion endpoint, no agent | `PLANNED` |
| Detect and correlate | No rule engine, no correlation | `PLANNED` |
| Serve several organisations | No tenant model | `PLANNED` |
| Run in production | No HTTPS, no packaging, no deployment procedure validated | `PLANNED` |
| Report and export | No reporting or export code | `PLANNED` |

## Explicit non-goals of the current code

- The application is not an IDS, a firewall or a packet analyser; it consumes what those tools
  produce.
- It does not modify network equipment.
