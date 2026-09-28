# Production deployment

**Status: `PLANNED`. No production deployment exists, and no deployment procedure has been
validated.** This page states the requirements the code already supports, not a recipe that has
been executed.

## What the application expects

| Requirement | Why | Status |
|---|---|---|
| `APP_ENV=production` | Turns on the `Secure` session cookie; also makes the test suites refuse to run | Supported from `.env`, `SetEnv` or the process environment |
| HTTPS only, HTTP redirected | Credentials and session cookies travel in clear otherwise | `PLANNED` (infrastructure) |
| `Strict-Transport-Security` from the web server | Prevents downgrade | `PLANNED` |
| Document root pointing at the project, with `AllowOverride All` (or the equivalent `<Directory>` rules) | The exposure policy relies on it | Documented in `backend/tests/README.md` |
| Dedicated PHP-FPM pool and system user | Session files are otherwise shared with every other application on the host | `PLANNED` |
| Private `session.save_path` outside the web root, mode 0700, `gc_maxlifetime ≥ 21600` | Session isolation and the 6-hour lifetime | `PLANNED` |
| `expose_php = Off`, `ServerTokens Prod`, `ServerSignature Off` | Fingerprinting | `PLANNED` (the application already removes `X-Powered-By`) |
| `display_errors = Off`, `log_errors = On`, log file mode 0640 with rotation | Traces must not reach clients, nor every local account | `PARTIAL` |
| Database account limited to the application schema | Least privilege | Already the case in the laboratory |
| Backups | Nothing backs the database up today | `PLANNED` |

## Before a first deployment

1. Remove the credentials currently pasted into the working copy of `README.md`, and rotate
   anything that was ever committed or shared.
2. Create the schema with the migrations, then one administrator account.
3. Set `APP_ENV=production` and verify the cookie carries `Secure` (`ProductionCookieTest`
   covers the mechanism).
4. Run the test suite **against a non-production database**; the suites refuse to run when
   `APP_ENV=production`, on purpose.
5. Verify the exposure policy from outside: `.env`, `.git`, `backend/src` must answer 403.

## Not available

No container image, no CI pipeline, no infrastructure-as-code, no blue/green or rollback
procedure. Adding any of them is a project in itself (`PLANNED`).
