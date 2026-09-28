# Security tests

Each security control has a suite that fails if the control is removed. Several were written
against the vulnerable code first, so their ability to detect the flaw is established.

| Suite | Control | Highlights |
|---|---|---|
| `WebExposureTest` | Web exposure | `.env`, `.git`, sources, migrations, vendor, tests → 403/404 over HTTP and HTTPS; no directory listing; the interface and API still answer; the test tooling refuses any web SAPI |
| `LoginThrottleTest` | Brute force | Free attempts, progressive delays, reset rules, shared-address fairness, collation variants of one identifier, 10 truly concurrent requests, nothing identifying stored |
| `LoginTimingTest` | Account enumeration | Every failure case costs one bcrypt verification; a throttled attempt never reaches the users table |
| `SensitiveLoginTraceTest` | Secrets in traces | A real uncaught exception in the sign-in path; password and identifier never appear, whole or as any 6-character part, at two trace-length settings |
| `LoginInputValidationTest` | Input bounds | Values at, below and above the limits; 1 MB and 10 MB inputs answer 401 without an exception (they used to cause a 500) |
| `SessionEstablishmentTest` | Session integrity | `session_start` and `session_regenerate_id` failures never produce a "signed in" answer; no partial session survives |
| `AuthenticationCacheHeadersTest` | Caching | `no-store` on every sign-in and sign-out answer, including 401, 429 and 500 |
| `LoginCsrfTest` | Login CSRF | Valid credentials sent as `text/plain`, `multipart/form-data` or urlencoded never sign anyone in; foreign and opaque `Origin` refused |
| `HttpSurfaceTest` | Method handling and fingerprinting | 405 + `Allow` on known paths; no `X-Powered-By` |
| `PasswordRehashTest` | Hash policy | Weaker hashes upgraded, stronger never downgraded, a concurrent password change never overwritten |
| `ProductionCookieTest` | Transport | `Secure` cookie when `APP_ENV=production` from any source; absent in development |
| `AuthenticationAuditTest` | Audit trail | Failure rows identical for every cause; no password, identifier, hash or session id stored |
| `SessionRevocationTest` | Revocation | Disabled, locked or deleted account loses its session on the next request |
| `AuthorizationApiTest` | RBAC | The full role matrix over HTTP |

## What is not tested

- No test drives a real browser (the checks run during development were scratch scripts, not
  versioned).
- No fuzzing, no dependency scanning, no static analysis in the repository.
- No test covers the infrastructure findings (session storage isolation, HTTPS, log
  permissions), because they are not application behaviour.
