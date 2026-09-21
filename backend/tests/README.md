# Backend tests

Standalone PHP scripts, no framework and no extra dependency. Each `*Test.php` exits `0`
when every check passes.

```bash
/opt/lampp/bin/php backend/tests/run.php                        # every suite
/opt/lampp/bin/php backend/tests/IncidentWorkflowTest.php       # one suite
```

Use the XAMPP PHP (`/opt/lampp/bin/php`): it provides `pdo_mysql` and `pdo_sqlite`.

| Suite | Covers |
|---|---|
| `SessionExpirationTest.php` | 6-hour absolute session (APP-03.6), in-memory SQLite |
| `LoginInputValidationTest.php` | sign-in input bounds (APP-07.4.4): identifier ≤ 254 characters (trimmed), password ≤ 1024 bytes; longer values rejected whole with the generic 401 before the throttle and any query (recording PDO: no users query, no bucket); values at the bounds still sign in, one past is refused; Unicode, emoji, NUL, non-string types; 1 MB / 10 MB inputs answer 401 with no exception, on a private server and on the running Apache (10 MB used to end in a 500) |
| `SensitiveLoginTraceTest.php` | no password in logged exception traces (APP-07.4.3): the real `LoginController` and `AuthenticationService` in a separate process (`support/login_trace_probe.php`) whose user lookup throws, uncaught, logged with the trace string limit at 15 and 1000000; random short, long, special-character and hash-like passwords never appear, whole or as any 6-character part; the argument shows as `SensitiveParameterValue`; no bcrypt hash logged |
| `LoginTimingTest.php` | no account enumeration by timing (APP-07.4.2): unknown, deleted, inactive, locked and active + wrong password each cost one bcrypt verification (reference hash, bcrypt cost 12, not stored), identical 401 responses and no session, active + correct password still 200, a throttled attempt (429) never reaches the users table, nothing sensitive in the logs |
| `LoginThrottleTest.php` | sign-in throttling (APP-07.4.1): 5 free attempts per identifier and address, 10 per identifier from any address, then 30 s doubling up to 15 min; reset after 1 h idle and on success; shared address not blocked by another identifier; case/accent variants share a bucket; unknown and existing identifiers answered identically; 429 + Retry-After; 10 truly concurrent requests (PHP server with 10 workers); no identifier, address or password stored |
| `LogoutTest.php` | `POST /logout` (APP-07.3.2), real front controller over HTTP: session deleted server-side and cookie expired with its original attributes, old ID → 401, same generic 200 for valid, absent, unknown, corrupted or expired sessions, no session created, sign-in again after logout, POST only, client-supplied IDs/roles ignored |
| `SessionRevocationTest.php` | account re-read on every protected request (APP-07.3.1), real front controller over HTTP: inactive, locked, deleted or soft-deleted account → 401 and session destroyed, role changes applied on the next request, client-supplied role ignored, no session created for anonymous requests, APP-06 close rule intact |
| `IncidentWorkflowTest.php` | state machine (36 combinations × analyst/admin), close reserved to admin, history, lifecycle timestamps, rejected fields and values, rollback at every write step, column allow-list, read-only detail |
| `IncidentConcurrencyTest.php` | row lock with two real processes: stale transition refused, chained transitions, duplicate transition as no-op, role under contention |
| `IncidentApiTest.php` | real front controller (`backend/public/index.php`) over HTTP: roles, 401/404/400, injection attempts, detail contract, public user shape, sensitive-data scan, read-only GETs, PATCH permissions |
| `IncidentErrorHandlingTest.php` | controller error responses: simulated internal failures → generic 500 without internal details, 404, 422, 400, 401 |
| `WebExposureTest.php` | web exposure (no database write): `.env`, `.git`, tests, sources, migrations, vendor and composer files answer 403/404; no directory listing; frontend and API still served; bootstrap and runner refuse any non-CLI SAPI; `.env` ignored and untracked |

These scripts are developer/CI tools: `support/bootstrap.php` and `run.php` stop immediately
under any web SAPI, and Apache refuses the whole `backend/tests/` directory.

## Web exposure policy

The repository sits inside Apache's document root. `/.htaccess` refuses everything and turns
directory listing off; only `frontend/` and `backend/public/` re-allow access in their own
`.htaccess`. Dotfiles are refused everywhere. This relies on `AllowOverride All` for the
document root (XAMPP default). On a host where `.htaccess` is disabled, the equivalent
server-level configuration is:

```apache
<Directory "/opt/lampp/htdocs/cyberguard-campus">
    Options -Indexes
    Require all denied
</Directory>
<Directory "/opt/lampp/htdocs/cyberguard-campus/frontend">
    Require all granted
</Directory>
<Directory "/opt/lampp/htdocs/cyberguard-campus/backend/public">
    Require all granted
</Directory>
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
```

## Data

The incident suites use the database configured in `.env` and refuse to run when
`APP_ENV=production`. Every row they create (users, incidents, alerts, devices; history
cascades) carries a per-run marker and is deleted at the end; each suite, and `run.php`,
checks that the counts of `incidents`, `incident_history`, `audit_logs`, `users`, `alerts`,
`devices`, `events` and recent `login_throttle` buckets are back to their initial values; sign-in
throttling buckets created by a run are removed by its cleanup. Existing data is never touched.

Authenticated requests use sessions written to a private temporary session store for the
temporary test users: no credentials are stored or needed.
