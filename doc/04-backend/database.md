# Database access layer

The schema itself is documented in [09-database/schema.md](../09-database/schema.md).

## Connection (`Database/Database.php`)

- Static factory, one PDO per process, built from `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD` (read from `$_ENV`, then `$_SERVER`; a missing variable throws).
- DSN: `mysql:host=…;port=…;dbname=…;charset=utf8mb4`.
- Options: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false`,
  `STRINGIFY_FETCHES = false`.
- A connection failure is wrapped in `RuntimeException('Database connection failed.')`, so the
  DSN and credentials never appear in the message (`PDO::__construct`'s password parameter is
  marked sensitive by PHP itself).

## Repository conventions

- Every statement is prepared; values are always bound. No string interpolation of user input.
- Column allow-lists on writes: `IncidentRepository` builds its `SET` clause from a fixed list.
- Reads map rows to models (`User`, `Incident`, `Event`, `Alert`, `Device`); models expose
  `toArray()` with the fields the API returns.
- `UserRepository` is the only place that reads `password_hash`, and the model never exposes it
  through `toArray()`.
- Write paths that must be atomic use a transaction plus `SELECT … FOR UPDATE`
  (`IncidentRepository`) or an upsert under row locks (`LoginThrottleRepository`).

## Repositories

| Repository | Reads | Writes |
|---|---|---|
| `UserRepository` | by username/email, by uuid, by id (active only) | `last_login_at`, `password_hash` (compare-and-set), `create()` (used by tests) |
| `IncidentRepository` | list, detail, history | update + history row, inside a transaction |
| `EventRepository`, `AlertRepository`, `DeviceRepository` | full list ordered by recency | — |
| `MetricsRepository` | one statement, five counters | — |
| `AuditLogRepository` | recent-event lookup (throttle de-duplication) | authentication events |
| `LoginThrottleRepository` | bucket keys and rows | bucket counters, purge of idle rows |

## Notable SQL choices

- Throttle keys are computed by MariaDB with `SHA2(… WEIGHT_STRING(identifier COLLATE
  utf8mb4_unicode_ci) …)`, so identifiers that the database considers equal share a bucket.
- `AuditLogRepository::record()` resolves `user_id` through a sub-query, so a deleted account
  does not break the insert (it stores `NULL`).
- No stored procedure, trigger or view is used.
