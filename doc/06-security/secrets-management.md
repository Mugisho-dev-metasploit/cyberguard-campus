# Secrets management

## Where secrets live

| Secret | Location | Protection |
|---|---|---|
| Database password | `.env` (`DB_PASSWORD`) | Not tracked by Git (`.gitignore`), refused over HTTP by `.htaccess`, file mode 664 |
| Session name | `.env` (`SESSION_NAME`) | Not a secret, but configuration |
| Password hashes | `users.password_hash` | bcrypt cost 12 |
| Reference hash (timing equalisation) | Constant in `AuthenticationService` | Not a secret: generated from discarded random bytes, and a match is refused anyway |

`.env.example` carries placeholders only and is tracked; `.env` is not tracked.

## Rules the code follows

- No secret is ever written to a response, a log line or an exception trace: `$password`,
  `$identifier` and hashes are annotated `#[\SensitiveParameter]`, and PHP marks
  `PDO::__construct`'s password and `password_verify`'s password itself.
- A failed database connection is re-thrown as `RuntimeException('Database connection failed.')`,
  so the DSN never reaches a trace.
- Tests never store a fixed password: every temporary account gets a random password for the
  run, stored only as a hash and deleted afterwards.

## Known issues

| Issue | Detail | Status |
|---|---|---|
| Credentials pasted into `README.md` | The working tree contains an uncommitted edit adding a database password and a demo account password in clear. **Remove it before committing**; if it was ever committed or shared, treat both as compromised and rotate them | Open, `ACTION REQUIRED` |
| A database password exists in the Git history | An earlier commit added `.env`; the credential was rotated afterwards, and `.env` is now ignored | Mitigated |
| Old traces in `php_error_log` | Written before the redaction work; the file is world-readable | `PLANNED` (infrastructure) |
| No secret manager | `.env` on disk is the whole mechanism | `PROPOSED` |
| No rotation procedure | Rotation was done manually once | `PLANNED` |

## If a secret leaks

1. Rotate it (database password: change it on the server and in `.env`, in that order, and
   verify connectivity immediately).
2. Assume the old value is public; do not rely on history rewriting alone.
3. Record what was done — the application's audit trail does not cover configuration changes.
