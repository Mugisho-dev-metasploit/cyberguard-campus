# Database security

| Item | Value |
|---|---|
| Engine | MariaDB 11.8, listening on `127.0.0.1:3306` only |
| Application account | `cyberguard@localhost` |
| Privileges | `SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER` on the `cyberguard` schema only — no `DROP`, no `GRANT`, nothing outside the schema |
| Connection | PDO, `utf8mb4`, `EMULATE_PREPARES = false`, exceptions on error |
| Credentials | `.env`, outside version control, refused over HTTP |
| Status | `IMPLEMENTED` |

## Controls

- **Prepared statements everywhere.** Values are always bound; the only assembled SQL fragment
  is a `SET` clause built from an allow-list of column names.
- **Least privilege at the schema level.** The account cannot reach other databases, cannot
  drop the schema and cannot grant rights.
- **Integrity in the schema.** Foreign keys, enums, check constraints (severity 1–4, username
  length) and unique keys enforce shape in the database, not only in PHP.
- **Atomic writes.** The incident update runs in a transaction with `SELECT … FOR UPDATE`; the
  throttle uses row locks and ordered locking to avoid deadlocks, with a retry.
- **No secret in the schema.** Only `users.password_hash`, which is a bcrypt hash and never
  leaves the repository layer.

## Limitations

| Limitation | Note | Status |
|---|---|---|
| The account can `ALTER` and `CREATE` | Convenient for applying migrations by hand; a production account should not need it once the schema is stable | `PROPOSED` |
| No encryption at rest | Standard MariaDB data files | `PLANNED` |
| No backup | Nothing in the repository backs the database up | `PLANNED` — see [12-operations/backups.md](../12-operations/backups.md) |
| Credential rotation | Manual; the rotation done during the security work was a one-off procedure | `PARTIAL` |
| No row-level restriction | Any authenticated role reads every row | `PLANNED` |
