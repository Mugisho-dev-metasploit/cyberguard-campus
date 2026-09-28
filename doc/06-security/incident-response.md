# Incident response

Two different things share the word "incident": the **records the product manages**, and **an
incident affecting the platform itself**. This page is about the second.

## What the platform gives a responder

| Question | Where to look | Status |
|---|---|---|
| Who signed in, from where, when? | `audit_logs` (`auth.login.success`) | `IMPLEMENTED` |
| Were there guessing attempts? | `audit_logs` (`auth.login.failure`, `auth.login.throttled`) | `IMPLEMENTED` |
| Was a session cut because an account was disabled? | `audit_logs` (`auth.session.revoked`) | `IMPLEMENTED` |
| Who changed an incident, and from what to what? | `incident_history` | `IMPLEMENTED` |
| What did a user read? | Nothing records reads | `PLANNED` |
| What did an administrator change in the database? | Nothing records it | `PLANNED` |

## Containment actions available today

| Action | How |
|---|---|
| Cut a user's access | Set `users.status` to `inactive` or `locked`, or set `deleted_at`: the session is destroyed on the next protected request |
| Force everyone out | Delete the session files of the store (infrastructure action), or stop the service |
| Stop the exposure | Stop Apache |
| Rotate the database credential | Change it on the server and in `.env`, then verify connectivity |
| Block an address | Firewall or Apache configuration; the application has no block list |

There is no "sign out everywhere", no account lock triggered by the application, and no
administrative interface: containment is SQL and infrastructure work.

## Recommended sequence (`PROPOSED`, not automated)

1. Identify the account and the window from `audit_logs`.
2. Disable the account (`status = 'inactive'`), which revokes its sessions.
3. Rotate what the account could reach: its password, and the database credential if the host
   itself is suspect.
4. Preserve evidence: copy `audit_logs`, `incident_history`, the Apache access log and
   `php_error_log` before rotation removes context.
5. Record the timeline; the application will not do it for you.

## Gaps worth closing first

- No alerting on the platform's own security events (`PLANNED`).
- No read audit, so data exfiltration by a legitimate account leaves no trace (`PLANNED`).
- No backup, so recovery after destructive action is not possible today (`PLANNED`, see
  [12-operations/backups.md](../12-operations/backups.md)).
