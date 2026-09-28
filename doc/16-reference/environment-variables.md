# Environment variables

Defined in `.env` (not tracked) from the template `.env.example` (tracked, placeholders only).
Loaded by `vlucas/phpdotenv` in immutable mode: a variable already present in the environment is
not overwritten.

**Never put a real secret in documentation, in `.env.example`, or in any tracked file.**

| Variable | Purpose | Required | Default | Example | Sensitivity | Used by |
|---|---|:--:|---|---|---|---|
| `APP_NAME` | Product name | no | — | `CYBERGUARD CAMPUS` | none | **No code reads it** (`UNKNOWN`) |
| `APP_ENV` | Environment; `production` turns on the `Secure` cookie and makes the test suites refuse to run | no | `development` | `production` | none | `SessionManager`, `backend/tests/support/bootstrap.php` |
| `APP_DEBUG` | Intended debug flag | no | — | `false` | none | **No code reads it** (`UNKNOWN`) |
| `APP_URL` | Base URL | no | — | `http://localhost/cyberguard-campus` | none | **No code reads it** (`UNKNOWN`) |
| `DB_HOST` | Database host | **yes** | none — missing throws | `127.0.0.1` | low | `Database` |
| `DB_PORT` | Database port | **yes** | none | `3306` | low | `Database` |
| `DB_DATABASE` | Schema name | **yes** | none | `cyberguard` | low | `Database` |
| `DB_USERNAME` | Database account | **yes** | none | `cyberguard` | medium | `Database` |
| `DB_PASSWORD` | Database password | **yes** | none | `CHANGE_ME` | **high** | `Database` |
| `SESSION_NAME` | Session cookie name | no | `cyberguard_session` | `cyberguard_session` | none | `SessionManager`, tests |

## Notes

- `APP_ENV` is read from `$_ENV`, `$_SERVER` **and** `getenv()`: production is recognised as
  soon as any source says so, so a deployment that sets it in the web server or the process
  environment never loses the `Secure` cookie.
- `DB_*` are read from `$_ENV` then `$_SERVER`; a missing one throws during bootstrap, which
  surfaces as HTTP 500 with an empty body.
- `APP_NAME`, `APP_DEBUG` and `APP_URL` exist in the template but no code reads them today. They
  are kept for documentation; do not assume they change behaviour.
- Changing `SESSION_NAME` invalidates every existing session cookie.

## Test-only variables

| Variable | Purpose |
|---|---|
| `CG_BASE_URL` | Overrides the base URL used by the HTTP suites (default `http://localhost/cyberguard-campus`) |
