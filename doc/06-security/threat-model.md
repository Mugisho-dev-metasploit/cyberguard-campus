# Threat model

Scope: the application as deployed today — Apache + PHP + MariaDB on one host, reachable over
HTTP on a laboratory network, with a handful of accounts.

## Assets

| Asset | Why it matters |
|---|---|
| Account credentials | Give access to the whole dataset |
| Sessions | Equivalent to credentials while valid |
| Security records (events, alerts, incidents) | Describe the network's weaknesses and the response |
| Audit trail | Evidence of who did what |
| Database credentials in `.env` | Full access to the data |

## Actors

| Actor | Capability assumed |
|---|---|
| Anonymous network attacker | Can reach ports 80/443 and send arbitrary HTTP |
| Malicious or curious authenticated user | A valid `viewer` account |
| Local user on the host | Shell access to the machine |
| Co-hosted application | Another application under the same Apache/PHP user |

## Threats and current answer

| # | Threat | Answer today | Residual |
|---|---|---|---|
| T1 | Password guessing | Throttling per identifier and per identifier+address, progressive delays | Distributed spraying across many accounts is only limited per account |
| T2 | Account enumeration | Identical bodies, headers and work for every failure | Response-size and status parity verified; network jitter is not a channel the app controls |
| T3 | Session theft over the wire | `HttpOnly`, `SameSite=Lax`, `Secure` in production | **HTTP in the laboratory: a sniffer sees the cookie and the password** |
| T4 | Session fixation | Regeneration at sign-in, old id destroyed, strict mode | — |
| T5 | Stale privileges | Account and role re-read on every request; revocation destroys the session | A compromised session is valid until it expires or the account changes |
| T6 | Login CSRF | JSON required, foreign `Origin` refused | Behind a proxy that rewrites `Host`, legitimate sign-ins would be refused (fails closed) |
| T7 | CSRF on other writes | `SameSite=Lax` cookie, JSON-only body for `PATCH` | No token; a same-site scripting flaw would defeat it |
| T8 | XSS | No HTML strings anywhere in the interface | No Content-Security-Policy header |
| T9 | SQL injection | Prepared statements, allow-listed columns | — |
| T10 | IDOR | Every authenticated role may read every record by design | No ownership or tenant boundary (`PLANNED`) |
| T11 | Secrets in logs | Sensitive parameters redacted in traces | Old traces from before the fix remain in `php_error_log` |
| T12 | Source or `.env` disclosure | Everything but the interface and the front controller is refused | Depends on `AllowOverride All`; a test checks it |
| T13 | Denial of service | bcrypt cost bounds guessing; throttle purges its own table | No request rate limit outside sign-in; list endpoints return whole tables |
| T14 | Co-hosted application reading sessions | — | **Real**: same user, shared session directory (`PLANNED`, infrastructure) |
| T15 | Host compromise | Out of scope for the application | — |

## Not defended against

- An attacker on the local network while the laboratory runs over plain HTTP (T3).
- A malicious application on the same PHP user (T14).
- A user with legitimate read access exfiltrating data (no per-record restriction, T10).
