# Authentication (backend)

Code: `Controllers/LoginController.php`, `Services/AuthenticationService.php`,
`Services/LoginThrottle.php`, `Services/AuthenticationAudit.php`, `Core/SessionManager.php`,
`Middleware/AuthenticationMiddleware.php`.

The security rationale for each control is in
[06-security/authentication-security.md](../06-security/authentication-security.md); this page
describes the mechanics.

## Sign-in order of operations

| # | Check | Failure |
|---|---|---|
| 1 | `Content-Type` is `application/json` | 415 `Unsupported content type.` |
| 2 | `Origin`, when sent, matches the server origin | 403 `Cross-origin sign-in refused.` |
| 3 | `identifier` and `password` are non-empty strings | 401 `Invalid credentials.` |
| 4 | identifier ≤ 254 characters (trimmed), password ≤ 1024 bytes | 401 `Invalid credentials.` |
| 5 | Throttle bucket not blocked | 429 + `Retry-After` |
| 6 | Credentials verified (one bcrypt verification in every case) | 401 `Invalid credentials.` |
| 7 | Session established (start + regenerate + checks) | 500 `Unable to sign in.` |
| — | Success | 200 with the public user payload and a session cookie |

Steps 1–4 happen before the throttle, so malformed requests never consume budget.

## Credential verification (`AuthenticationService::authenticate`)

1. Trim the identifier, re-check bounds.
2. `findByUsernameOrEmail` — matches `username` or `email`, excludes soft-deleted rows.
3. If the account is missing or not `active`, it is treated as absent.
4. **Exactly one** `password_verify()` per attempt: against the account hash when active,
   otherwise against `REFERENCE_HASH` (bcrypt cost 12, generated from discarded random bytes).
5. On success: upgrade the stored hash if it is weaker than the policy (bcrypt cost 12), with a
   compare-and-set so a password changed meanwhile is never overwritten; update `last_login_at`;
   reload and return the user.

## Sessions (`SessionManager`)

| Property | Value |
|---|---|
| Lifetime | `SESSION_LIFETIME_SECONDS = 21600` (6 h), absolute, written once at sign-in (`__session_started`) |
| Cookie | name from `SESSION_NAME`, `Path=/`, `HttpOnly`, `SameSite=Lax`, `Secure` when `APP_ENV=production` (read from `$_ENV`, `$_SERVER` or `getenv`) |
| Fixation | `session_unset()` + `session_regenerate_id(true)` at sign-in; the old id is deleted |
| Establishment | `session_start()` and `session_regenerate_id()` results are checked, and the new id must differ; otherwise the session is abandoned and the sign-in fails |
| Anonymous requests | No session is created when no session cookie is presented |
| Expiry | Checked server-side on every request; an expired session is destroyed |
| Sign-out | `logout()` destroys the session and expires the cookie; idempotent |

## Per-request account check (`AuthenticationMiddleware`)

On every protected route: session valid → `resolveSessionUser(userId)` re-reads the account
(`active`, not soft-deleted). If it is gone or disabled, the session is destroyed, an
`auth.session.revoked` event is recorded and the answer is 401. Otherwise the session role is
refreshed from the database before authorization runs.
