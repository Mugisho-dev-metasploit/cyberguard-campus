# Testing strategy

## Shape

21 standalone PHP scripts in `backend/tests`, no framework and no extra dependency. Each
`*Test.php` prints one line per check and exits 0 when every check passes. `run.php` executes
them all, each in its own process.

```bash
/opt/lampp/bin/php backend/tests/run.php                    # every suite
/opt/lampp/bin/php backend/tests/IncidentWorkflowTest.php   # one suite
```

Use the XAMPP PHP (`/opt/lampp/bin/php`): it provides `pdo_mysql` and `pdo_sqlite`.

## What the suites exercise

| Level | How | Suites |
|---|---|---|
| In process | Real classes, real database or in-memory SQLite | `SessionExpirationTest`, `LoginTimingTest`, `PasswordRehashTest`, parts of others |
| Over HTTP, private server | `php -S` running the real front controller, private session store | Most security suites |
| Over HTTP, real Apache | The running server, to prove production behaviour | `WebExposureTest`, parts of `LoginCsrfTest`, `HttpSurfaceTest`, `AuthenticationCacheHeadersTest`, `LoginInputValidationTest` |
| Concurrency | Several real processes or a PHP server with 10 workers | `IncidentConcurrencyTest`, `LoginThrottleTest` |

## Data rules

- The incident suites use the configured MariaDB database and **refuse to run when
  `APP_ENV=production`**.
- Every row a run creates carries a per-run marker and is deleted at the end.
- Each suite, and `run.php`, compares the counts of `incidents`, `incident_history`,
  `audit_logs`, `users`, `alerts`, `devices`, `events` and recent `login_throttle` buckets
  before and after: a run that leaves data behind fails.
- Temporary accounts get a random password per run, stored only as a hash. No fixed credential
  exists anywhere in the repository.

## Conventions

- `check(name, condition, detail)` records a result; `tk_finish($before)` prints them and
  compares the database.
- Test doubles live in `support/bootstrap.php`: `TkThrowingPdo` (fails a chosen statement),
  `TkRecordingPdo` (records statements).
- The tooling is CLI-only: `bootstrap.php` and `run.php` answer 404 under any web SAPI, and
  Apache refuses the whole `backend/tests/` directory.

## Current result

At the analysed commit: **21 suites, 648 checks**. A full run needs Apache **and** MariaDB
running; with Apache stopped, the six HTTP-dependent suites fail (see
[12-operations/troubleshooting.md](../12-operations/troubleshooting.md)).

## Gaps

| Gap | Status |
|---|---|
| No frontend test in the repository (the browser checks done during development were scratch scripts) | `PLANNED` |
| No continuous integration | `PLANNED` |
| No coverage measurement | `PLANNED` |
| No load or performance test | `PLANNED` |
| The empty `tests/` tree at the repository root suggests an earlier plan (application, detection, integration, network, security) that was never populated | `PLANNED` |
