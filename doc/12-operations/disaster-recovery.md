# Disaster recovery

**Status: `PLANNED`.** With no backup (see [backups.md](backups.md)) and no deployment
automation, recovery today means rebuilding by hand and accepting data loss.

## What recovery looks like now

| Loss | Recovery | Data loss |
|---|---|---|
| Application files | `git clone`, `composer install`, restore `.env` | None (code is versioned) |
| `.env` | Rewrite it; the database password must be reset on the server | None, if the password is reset |
| Database | **No backup**: recreate the schema with the migrations and start from empty | Total |
| Session store | Nothing to do; users sign in again | None |
| Whole host | Reinstall the stack, then the two steps above | Total for data |

## Objectives worth agreeing on (`PROPOSED`)

| Objective | Suggestion |
|---|---|
| RPO (acceptable data loss) | 24 h with a daily dump |
| RTO (time to restore) | A few hours, given a documented rebuild |
| Priority | The audit trail and incident history matter most: they are the evidence |

## Rebuild checklist (`PROPOSED`)

1. Install Apache, PHP 8.2, MariaDB.
2. Clone the repository into the document root; `composer install`.
3. Restore `.env` (or recreate it and reset the database password).
4. Restore the database dump, or apply the migrations for an empty start.
5. Verify: `/health`, sign in, `WebExposureTest`, then the full suite on a non-production copy.
6. Record what was lost between the last dump and the incident.
