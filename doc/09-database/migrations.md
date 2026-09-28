# Migrations

Eight files, applied in order. There is no runner and no tracking table — see
[04-backend/migrations.md](../04-backend/migrations.md) for how they are applied.

| File | Creates | Notable content |
|---|---|---|
| `001_create_users.sql` | `users` | role and status enums, unique username/email, `deleted_at`, check on username length |
| `002_create_devices.sql` | `devices` | device type and environment enums, status enum |
| `003_create_events.sql` | `events` | network 5-tuple, severity check 1–4, raw/normalized JSON |
| `004_create_alerts.sql` | `alerts` | alert status enum, links to event, device and assignee |
| `005_create_incidents.sql` | `incidents` | incident status and priority enums, lifecycle timestamps, `incident_number` |
| `006_create_incident_history.sql` | `incident_history` | actor, previous/new status and assignee, cascade on incident |
| `007_create_audit_logs.sql` | `audit_logs` | action, address, user agent, success, details, six indexes |
| `008_create_login_throttle.sql` | `login_throttle` | digest key, scope enum, counters, `blocked_until` |

Files `001`–`007` predate the security work; `008` was added with sign-in throttling.

## Check constraints

Declared in the migrations (MariaDB enforces them):

- `users.username` length ≥ 3.
- `severity` between 1 and 4 on `events`, `alerts` and `incidents`.
- JSON columns are validated as JSON (MariaDB stores `JSON` as `LONGTEXT` plus a check).

## Adding a migration

1. Add `00N_create_<table>.sql` with a single `CREATE TABLE` (the existing style: explicit
   engine, charset, collation, indexes and foreign keys).
2. Apply it with the snippet in [04-backend/migrations.md](../04-backend/migrations.md).
3. If tests touch the new table, add it to the counters in `backend/tests/support/bootstrap.php`
   so a run still proves the database returned to its initial state.
