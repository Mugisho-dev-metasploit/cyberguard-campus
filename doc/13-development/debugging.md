# Debugging

## Where to look first

| Symptom | Look at |
|---|---|
| 500 with an empty body | `/opt/lampp/logs/php_error_log` |
| Unexpected status code | The controller for that route, then `Router` |
| 401 on every call | Session cookie, `SESSION_NAME`, account status |
| 403 | Role in the database, and the route's allowed roles |
| 415 / 403 on sign-in | `Content-Type` and `Origin` sent by the client |
| Test failing only under `run.php` | Suites run in their own process; check leftovers from a previous run |

## Techniques used in this project

**Run a single suite** — every suite is standalone:

```bash
/opt/lampp/bin/php backend/tests/LoginThrottleTest.php
```

**Drive the real front controller on a private port**, as the suites do:

```bash
/opt/lampp/bin/php -S 127.0.0.1:8099 -t backend/public
curl -s -i -H 'Content-Type: application/json' \
  -d '{"identifier":"x","password":"y"}' http://127.0.0.1:8099/index.php/login
```

**Inspect without polluting the real database**: use a throwaway `session.save_path` and the
`tk_*` helpers; never point a test run at a production database (the suites refuse when
`APP_ENV=production`).

**Watch the audit trail** while reproducing an authentication problem:

```sql
SELECT created_at, action, user_id, ip_address FROM audit_logs ORDER BY id DESC LIMIT 20;
```

## Cautions

- `display_errors` is off: a fatal error shows an empty body, not a message. Always read the
  PHP log.
- Stack traces redact passwords and identifiers by design — that is not a broken log.
- Sign-in attempts consume throttle budget; while debugging, use distinct identifiers or clear
  the buckets for the one you are testing.
