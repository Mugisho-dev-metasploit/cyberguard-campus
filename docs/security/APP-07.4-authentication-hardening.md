# APP-07.4 — Authentication hardening: status and deployment requirements

Scope: `POST /login`, `POST /logout`, sessions, and the authentication events of CYBERGUARD
CAMPUS. Every application-level finding is fixed and covered by `backend/tests/` (see
`backend/tests/README.md`). The items below that are marked *infrastructure* or *future feature*
cannot be solved in the application code; they are requirements for the production deployment or
for features that do not exist yet.

## Status

| Finding | Subject | Status |
|---|---|---|
| APP074-01 | Brute force: progressive throttling per identifier and per identifier + address | Fixed |
| APP074-02 | Account enumeration by timing: one bcrypt verification for every failure | Fixed |
| APP074-03 | Password in exception traces (`#[\SensitiveParameter]`) | Fixed |
| APP074-04 | HTTPS / Secure cookie | Application part fixed; HTTPS is infrastructure |
| APP074-05 | Login CSRF: JSON body only (415), foreign `Origin` refused (403) | Fixed |
| APP074-06 | Oversized sign-in input (identifier ≤ 254 characters, password ≤ 1024 bytes) | Fixed |
| APP074-07 | Session establishment failure never reported as a sign-in | Fixed |
| APP074-08 | Shared session storage | Infrastructure |
| APP074-09 | bcrypt uses the first 72 bytes of a password | Future feature (password change/reset) |
| APP074-10 | Password hashes upgraded to bcrypt cost 12, never downgraded, never over a concurrent change | Fixed |
| APP074-11 | Authentication events in `audit_logs` | Fixed |
| APP074-12 | 405 + `Allow` for a known path with another method | Fixed |
| APP074-13 | `X-Powered-By` removed; `Server` header belongs to Apache | Application part fixed; `Server` is infrastructure |
| APP074-14 | `Cache-Control: no-store` / `Pragma: no-cache` on sign-in and sign-out | Fixed |
| APP074-16 | Sign-in form posts (no credentials in URLs without JavaScript) | Fixed |
| APP074-17 | Two `Set-Cookie` on sign-in (the first ID is destroyed at once) | Rejected: no risk |
| APP074-18 | Identifier in exception traces (`#[\SensitiveParameter]`) | Fixed |
| APP074-19 | Throttle reset failure after an established session | Fixed |
| APP074-20 | `php_error_log` permissions and old traces | Infrastructure |

## Production requirements (infrastructure)

**HTTPS (APP074-04).** Serve the application over HTTPS only and redirect HTTP to HTTPS. Set
`APP_ENV=production` (in `.env`, with Apache `SetEnv`, or in the PHP-FPM/container environment —
any of them is enough): the session cookie then carries `Secure`, in addition to `HttpOnly` and
`SameSite=Lax`. Send `Strict-Transport-Security: max-age=31536000` from the web server once HTTPS
works everywhere. Behind a reverse proxy, keep the original `Host` and terminate TLS so that the
browser's `Origin` still matches `scheme://Host` as seen by PHP; otherwise sign-in is refused
(403) — the check fails closed. The application trusts no `X-Forwarded-*` header.

**Session storage (APP074-08).** In XAMPP every application of `htdocs` runs as the same user and
shares `/opt/lampp/temp` (mode 0777, no sticky bit) for its sessions: another application could
read or write CYBERGUARD sessions, and its garbage collector (24 minutes) can delete them. A
different directory would not change this, since all applications run as the same user. In
production run CYBERGUARD in its own PHP-FPM pool under a dedicated user, with a private
`session.save_path` outside the web root (owned by that user, mode 0700) and
`session.gc_maxlifetime` ≥ 21600. No Redis or database is required.

**Version headers (APP074-13).** `Server: Apache/… OpenSSL/… PHP/…` comes from Apache
(`ServerTokens Full` in `httpd-default.conf`). Set `ServerTokens Prod` and `ServerSignature Off`
for the server; this is not changed in this repository because it applies to every application of
the host. Also set `expose_php = Off` (the application already removes `X-Powered-By`).

**PHP error log (APP074-20).** `/opt/lampp/logs/php_error_log` is not served over HTTP (outside
the document root, no alias), but it is readable by every local account (0644) and contains
traces written before APP-07.4.3, with the first 15 characters of some submitted values
(test values). Rotate it (logrotate), restrict it to `0640` with an administrators group, and
securely delete the rotated files that predate APP-07.4.3.

## Policies for future features

**Password length (APP074-09).** bcrypt only uses the first 72 bytes. Sign-in accepts up to
1024 bytes and never truncates; existing accounts keep working. When a password change or reset
feature is built: refuse new passwords longer than 72 bytes with a clear message (no silent
truncation), and hash them with the policy of `AuthenticationService` (bcrypt, cost 12).
Pre-hashing before bcrypt is not used: it would change the stored format and need a migration.

## Authentication audit (APP074-11)

Events in `audit_logs`: `auth.login.success` (account), `auth.login.failure` (no account, no
identifier: identical for every cause), `auth.login.throttled` (at most one per address and
minute), `auth.logout` (account), `auth.session.revoked` (account; NULL when it was deleted).
Each row has the client address (validated) and the user agent (valid UTF-8, no control
characters, ≤ 255 characters). Never stored: password, hash, identifier typed, session ID,
cookie. Recording is best effort and never changes the outcome. Expired sessions are not
recorded (they end inside `SessionManager`, below the service layer). Retention is a deployment
decision: purge `auth.%` rows older than the retention period (for example 365 days) with a
scheduled job; the `(action, created_at)` and `(created_at)` indexes support it.
