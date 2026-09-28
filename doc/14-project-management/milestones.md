# Milestones

Reconstructed from the Git history; dates are commit dates. Ticket identifiers come from the
commit messages.

| Date | Milestone | Evidence |
|---|---|---|
| 2026-09-11 | Project initialised; backend architecture, database layer and user repository | `7e21fc2`, `e2a3bf3`, `ac096ff`, `70af223` |
| 2026-09-11 | Authentication service | `9277408` |
| 2026-09-11 → 09-13 | Front controller, routing, middleware pipeline | `c32b9e7`, `3f9dc13` |
| 2026-09-14 | Incident read and update endpoints | `2d7499e`, `be19416` |
| 2026-09-15 | Read endpoints for events, alerts, devices, metrics | `b84bab5`, `bb44d32`, `24da659`, `b16552c` |
| 2026-09-21 | APP-05: security operations interface completed | `9792bca` |
| 2026-09-21 | APP-07.2: web exposure restricted, `.env` untracked, test tooling guarded | `efe35b9` |
| 2026-09-21 | APP-07.3: session revocation, server-side logout, frontend integration | `1a7324b`, `0c4a252`, `6714ba9` |
| 2026-09-21 → 09-22 | APP-07.4: login hardening (throttling, timing, traces, input bounds, session establishment, cache headers, CSRF, rehash, HTTP surface) | `abd72cb`, `d69d0c9`, `e104d55`, `e8e51c7`, `fb5f298` |
| 2026-09-22 | Authorization and incident input validation tests | `54bb141` |

## Milestone pattern

Two phases are visible: **building the application** (11–15 September) and **hardening it**
(21–22 September), the second driven by numbered security tickets with an audit, a fix and a
test suite each.

## Not recorded anywhere

Planned dates, effort, sprint boundaries or a release plan. No tag and no version number exists
in the repository, so "version" cannot be stated (`UNKNOWN`).
