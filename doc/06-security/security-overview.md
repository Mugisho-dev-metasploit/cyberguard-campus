# Security overview

Security work on this application has been done as a sequence of audited tickets (APP-07.1 to
APP-07.4). This section describes the controls **as they exist in the code**, with their
limits. The authentication hardening status and the production requirements are also summarised
in `docs/security/APP-07.4-authentication-hardening.md`.

## Control summary

| Area | Control | Status |
|---|---|---|
| Web exposure | Only `frontend/` and `backend/public/` are served; dotfiles refused; no directory listing | `IMPLEMENTED` |
| Authentication | Sessions, 6-hour absolute lifetime, regeneration, establishment checks | `IMPLEMENTED` |
| Brute force | Two-bucket throttling with progressive delays, 429 + `Retry-After` | `IMPLEMENTED` |
| Account enumeration | Identical responses and one bcrypt verification for every failure | `IMPLEMENTED` |
| Login CSRF | JSON content type required; foreign `Origin` refused | `IMPLEMENTED` |
| Input bounds | Identifier ≤ 254 characters, password ≤ 1024 bytes, rejected whole | `IMPLEMENTED` |
| Secrets in traces | Password, identifier and hashes redacted in exception traces | `IMPLEMENTED` |
| Session revocation | Account re-read on every protected request | `IMPLEMENTED` |
| Authorization | Role read from the database, checked per route, plus per-action rules | `IMPLEMENTED` |
| SQL injection | Prepared statements everywhere, column allow-lists on writes | `IMPLEMENTED` |
| XSS | No HTML strings in the interface; text nodes only | `IMPLEMENTED` |
| Caching of sensitive answers | `Cache-Control: no-store` on sign-in and sign-out | `IMPLEMENTED` |
| Audit trail | Authentication events in `audit_logs` | `IMPLEMENTED` |
| Password storage | bcrypt cost 12, upgraded on sign-in, never downgraded | `IMPLEMENTED` |
| Transport | HTTPS, HSTS, `Secure` cookie in production | `PARTIAL` — code ready, deployment `PLANNED` |
| Session storage isolation | Shared with other applications of the XAMPP host | `PLANNED` (infrastructure) |
| Version disclosure | `X-Powered-By` removed; `Server` still verbose | `PARTIAL` |
| Security headers (CSP, X-Frame-Options, Referrer-Policy) | None | `PLANNED` |
| CSRF on write endpoints other than sign-in | None beyond `SameSite=Lax` | `PARTIAL` |
| Multi-factor authentication | — | `PLANNED` |
| Tenant isolation | — | `PLANNED` |

## Reading order

1. [threat-model.md](threat-model.md) — what is defended against, and what is not.
2. [authentication-security.md](authentication-security.md) — the sign-in chain in detail.
3. [authorization-security.md](authorization-security.md) — roles and their limits.
4. [api-security.md](api-security.md) — transport-level controls on the API.
5. [database-security.md](database-security.md), [secrets-management.md](secrets-management.md),
   [network-security.md](network-security.md).
6. [logging-auditing.md](logging-auditing.md) — what is recorded, and what is deliberately not.
7. [incident-response.md](incident-response.md) — what the platform supports for its own
   incidents.

Every control below is covered by at least one test suite; see
[11-testing/security-tests.md](../11-testing/security-tests.md).
