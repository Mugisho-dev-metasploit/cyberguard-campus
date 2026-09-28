# Maintenance

## Routine work

| Task | How | Frequency |
|---|---|---|
| Update the single PHP dependency | `cd backend && composer update` then run the suite | On advisory |
| Update PHP, Apache, MariaDB | Host package management | Follow the distribution |
| Apply a new migration | See [../04-backend/migrations.md](../04-backend/migrations.md) | On schema change |
| Review accounts | `SELECT username, role, status, last_login_at FROM users;` | Periodically |
| Check table growth | `SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = 'cyberguard';` | Periodically |

## After any change to the application

```bash
/opt/lampp/bin/php backend/tests/run.php
```

Every suite must pass and the database counters must match before and after. The suites refuse
to run against a production environment, so use a copy.

## Things to watch

| Watch | Why |
|---|---|
| `audit_logs` growth | Nothing purges it |
| bcrypt cost versus hardware | Cost 12 is ~235 ms here; if hardware gets much faster, raise it (and the reference hash with it) |
| PHP version support | `#[\SensitiveParameter]` requires PHP 8.2 or later |
| `php_error_log` size and permissions | Rotation is not configured |

## Changes that need extra care

- **Raising the bcrypt cost**: update `AuthenticationService::PASSWORD_OPTIONS` *and* regenerate
  `REFERENCE_HASH` at the same cost, otherwise the timing protection becomes uneven. Existing
  hashes upgrade themselves on the next sign-in.
- **Renaming `SESSION_NAME`**: signs everyone out.
- **Adding a route**: declare `AuthenticationMiddleware` before `AuthorizationMiddleware`, or the
  role check runs on a role that was never refreshed.
