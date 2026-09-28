# Logging and auditing

## Authentication audit trail (`IMPLEMENTED`)

Written to `audit_logs` by `AuthenticationAudit`.

| Action | When | `user_id` | `success` |
|---|---|---|---|
| `auth.login.success` | Sign-in accepted and session established | the account | 1 |
| `auth.login.failure` | Any rejected sign-in | `NULL` | 0 |
| `auth.login.throttled` | A throttled attempt, at most one per address per minute | `NULL` | 0 |
| `auth.logout` | Sign-out with a valid session | the account | 1 |
| `auth.session.revoked` | Session ended because the account is gone or disabled | the account, `NULL` if deleted | 0 |

Stored with each row: client address (validated, otherwise `NULL`), user agent (valid UTF-8,
control characters removed, truncated to 255 characters), timestamp with microseconds.

**Never stored**: password, password hash, the identifier typed at sign-in, session identifier,
cookie value. Failure rows are identical for every cause, so the trail itself cannot be used to
enumerate accounts.

Recording is best effort: if the audit write fails, the authentication outcome is unchanged.

## What is not audited

| Event | Status |
|---|---|
| Incident changes | Covered by `incident_history` (actor, previous/new values) rather than `audit_logs` — `IMPLEMENTED` |
| Reads of business data | Not recorded — `PLANNED` |
| Session expiry | Not recorded: it happens inside `SessionManager`, below the service layer — `PLANNED` |
| Administrative actions (role change, account creation) | They happen in SQL, outside the application — `PLANNED` |
| Configuration changes | Not recorded — `PLANNED` |

## Application logs

There is no structured application log. PHP warnings and fatal errors go to the server's
`php_error_log`; Apache records requests in its access log (method, path, status, address — no
bodies). Both are system files outside the repository.

Two consequences:

- a user-visible error cannot be correlated with a server-side trace other than by timestamp;
- `php_error_log` contains traces written before the redaction work and is world-readable
  (`PLANNED`, infrastructure).

## Reading the trail

```sql
SELECT created_at, action, user_id, ip_address, success
FROM audit_logs
WHERE action LIKE 'auth.%'
ORDER BY created_at DESC
LIMIT 50;
```

There is no interface for this (`PLANNED`).
