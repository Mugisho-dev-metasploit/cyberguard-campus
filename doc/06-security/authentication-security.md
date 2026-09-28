# Authentication security

Mechanics are in [04-backend/authentication.md](../04-backend/authentication.md); this page
explains what each control defends and where it stops.

## Password storage

| Item | Value |
|---|---|
| Current implementation | bcrypt, cost 12, verified with `password_verify` |
| Threat addressed | Offline cracking after a database leak |
| Location | `AuthenticationService::PASSWORD_ALGORITHM/PASSWORD_OPTIONS` |
| Assumptions | The cost stays ahead of hardware; ~235 ms per verification on this host |
| Limitations | bcrypt only uses the first 72 bytes of a password; no password change feature exists |
| Improvement | Enforce ≤ 72 bytes when a password-setting feature is built (`PLANNED`) |
| Status | `IMPLEMENTED` |

Hashes weaker than the policy (other algorithm, or lower cost) are upgraded on a successful
sign-in, with a compare-and-set so a password changed meanwhile is never overwritten. Stronger
hashes are never downgraded.

## Brute force

| Item | Value |
|---|---|
| Current implementation | Two buckets: identifier+address (5 free attempts) and identifier alone (10). Then 30 s doubling to 15 min. Reset after 1 h idle or on success. Refused attempts are not counted and do not extend the delay |
| Threat addressed | Password guessing, including from several addresses |
| Location | `Services/LoginThrottle.php`, table `login_throttle` |
| Assumptions | `REMOTE_ADDR` is the real client; no proxy is trusted |
| Limitations | No address-only bucket, on purpose: one attacker behind the campus NAT must not lock out everyone else. Password spraying across many accounts is therefore only limited per account. An attacker can keep one account throttled (never locked) by attempting once per cooldown |
| Status | `IMPLEMENTED` |

Bucket keys are SHA-256 digests computed by the database from the identifier's collation
weights, so `Admin`, `ADMIN` and `ádmin` share a bucket while no identifier or address is
stored in clear.

## Account enumeration

| Item | Value |
|---|---|
| Current implementation | Unknown, inactive, locked, soft-deleted accounts and wrong passwords all get `401 Invalid credentials.`, with the same headers, and **one** bcrypt verification (a reference hash is used when no active account matches) |
| Threat addressed | Building a list of valid accounts |
| Location | `AuthenticationService::authenticate`, `REFERENCE_HASH` |
| Measured | Before: ~9 ms versus ~251 ms. After: all cases within a factor of 1.4 |
| Limitations | The reference hash is a constant in the source; it is not a secret and a match is refused anyway |
| Status | `IMPLEMENTED` |

## Session lifecycle

| Item | Value |
|---|---|
| Current implementation | 6-hour absolute lifetime written once at sign-in; regeneration with deletion of the old session; results of `session_start()` and `session_regenerate_id()` checked; no session created for anonymous requests; sign-out destroys the session and expires the cookie |
| Threat addressed | Fixation, replay of an old identifier, "signed in" answers without a session |
| Location | `Core/SessionManager.php` |
| Limitations | Session files are shared with other applications on the host (see [network-security.md](network-security.md)); no "sign out everywhere" |
| Status | `IMPLEMENTED` |

## Revocation

The account is re-read on every protected request: disabled, locked or deleted means the session
is destroyed, an `auth.session.revoked` event is recorded, and the answer is 401. The role used
for authorization is the one in the database, never the one stored at sign-in.

## Secrets in traces

`$password`, `$identifier` and password hashes are annotated `#[\SensitiveParameter]`, so a
logged stack trace shows `Object(SensitiveParameterValue)`. Verified with the trace string limit
at both 15 and 1 000 000 characters.

## Transport

`Secure` is set on the session cookie when `APP_ENV=production`, read from `.env`, the web
server environment or the process environment. The laboratory runs over HTTP, so in practice
the cookie and the password travel in clear there (`PLANNED`, see
[network-security.md](network-security.md)).
