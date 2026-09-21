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
`devices` and `events` are back to their initial values. Existing data is never touched.

Authenticated requests use sessions written to a private temporary session store for the
temporary test users: no credentials are stored or needed.
