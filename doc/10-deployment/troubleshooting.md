# Deployment troubleshooting

Problems observed on this project, with how they present and how to confirm them.

## The whole site answers 500 with an empty body

- **Symptoms**: every URL, including `/health`, returns 500 and nothing in the body.
- **Cause**: an uncaught exception at bootstrap — usually `.env` missing or a wrong
  `DB_*` value, since `Database::connection()` runs before routing.
- **Diagnosis**: `tail /opt/lampp/logs/php_error_log`; check that `.env` exists and is readable
  by the web server user.
- **Fix**: correct `.env`, then `curl …/index.php/health`.

## Every request from the tests returns status 0

- **Symptoms**: `run.php` shows HTTP-dependent suites failing with `→ 0`.
- **Cause**: Apache is not running.
- **Diagnosis**: `curl -s -o /dev/null -w '%{http_code}' http://localhost/`.
- **Fix**: `sudo /opt/lampp/lampp start` (or `startapache`), then re-run.

## `.env` or source files are downloadable

- **Symptoms**: `curl http://host/cyberguard-campus/.env` returns content instead of 403.
- **Cause**: `AllowOverride` is not `All` for the document root, so the `.htaccess` rules are
  ignored.
- **Diagnosis**: `/opt/lampp/bin/php backend/tests/WebExposureTest.php`.
- **Fix**: enable `AllowOverride All`, or apply the server-level `<Directory>` rules documented
  in `backend/tests/README.md`.

## Sign-in fails right after switching to production

- **Symptoms**: credentials are accepted (200) but every following request answers 401.
- **Cause**: `APP_ENV=production` sets `Secure` on the session cookie; over plain HTTP the
  browser never sends it back.
- **Fix**: deploy HTTPS, or keep `APP_ENV` non-production in a laboratory.

## Sign-in answers 415 or 403

- **415**: the client did not send `Content-Type: application/json`.
- **403**: the browser sent an `Origin` that does not match the server origin — typically a
  proxy rewriting `Host`, or an access through a different hostname than the one being used.

## Sessions disappear before six hours

- **Cause**: another application on the same host garbage-collects the shared session directory
  with a shorter lifetime.
- **Fix**: a private `session.save_path` for this application (`PLANNED`, infrastructure).

## Tests leave rows behind

- **Symptoms**: `run.php` ends with "database not back to its initial state".
- **Cause**: a suite crashed before its cleanup.
- **Diagnosis**: compare counts; test rows carry a per-run marker (`tk…`) in their names.
- **Fix**: delete the marked rows, then investigate the crash.
