# Operations

What running this application involves today, and what is missing. There is no runbook, no
scheduled job and no automation in the repository: everything below is manual.

## Daily

| Task | How | Status |
|---|---|---|
| Check the application answers | `curl …/index.php/health` | `IMPLEMENTED` (endpoint), manual |
| Check recent authentication activity | Query `audit_logs` | `IMPLEMENTED`, manual |
| Watch for errors | `tail /opt/lampp/logs/php_error_log` | manual |

## Periodic

| Task | Frequency | Status |
|---|---|---|
| Database backup | — | **None exists** (`PLANNED`) |
| Log rotation | — | Infrastructure (`PLANNED`) |
| Audit retention | — | No purge (`PLANNED`) |
| Dependency update (`composer update`) | on advisories | Manual; one dependency |
| Credential rotation | on suspicion | Manual procedure |

## Administrative actions, all in SQL

Because there is no administration interface:

```sql
-- Disable an account (revokes its sessions on the next request)
UPDATE users SET status = 'inactive' WHERE username = '…';

-- Change a role (applies on the next request)
UPDATE users SET role = 'analyst' WHERE username = '…';

-- Create an account: insert with a bcrypt hash (see 10-deployment/development.md)
```

These actions are **not** recorded in `audit_logs`; only their consequences are (a revoked
session appears as `auth.session.revoked`).

## Start and stop

```bash
sudo /opt/lampp/lampp start      # Apache + MariaDB
sudo /opt/lampp/lampp stop
sudo /opt/lampp/lampp startapache
```

## Gaps that matter most

1. **No backup** — a mistake or a disk failure loses everything.
2. **No alerting** on the platform's own errors or security events.
3. **No retention** on `audit_logs`.
4. **No administration interface**, so routine account work bypasses the audit trail.
