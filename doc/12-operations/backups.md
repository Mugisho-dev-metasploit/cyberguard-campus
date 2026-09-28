# Backups

**Status: `PLANNED`. There is no backup of anything: no script, no schedule, no documented
restore. A disk failure or a mistaken `DELETE` loses the data.**

## What would need backing up

| Item | Why | Frequency (`PROPOSED`) |
|---|---|---|
| MariaDB schema `cyberguard` | All business data and the audit trail | Daily, kept 30 days |
| `.env` | Credentials and configuration; **not** in version control | On change, stored as a secret |
| Application code | Already in Git | — |
| Session files | Transient; losing them signs users out | Not worth backing up |

## A minimal procedure (`PROPOSED`, not implemented)

```bash
# Dump (adapt credentials handling: do not put a password on the command line)
/opt/lampp/bin/mysqldump --single-transaction --routines --events cyberguard > cyberguard-$(date +%F).sql

# Restore into an empty schema
/opt/lampp/bin/mysql cyberguard < cyberguard-YYYY-MM-DD.sql
```

`--single-transaction` matters: InnoDB everywhere, so the dump stays consistent without locking
the application out.

## Rules to set before trusting a backup

1. Store it off the host.
2. Encrypt it: it contains password hashes and the full audit trail.
3. Test a restore on a scratch database — an untested backup is a hypothesis.
4. Record the retention and who may read it.
