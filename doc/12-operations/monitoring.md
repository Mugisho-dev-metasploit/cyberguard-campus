# Monitoring the platform

Not to be confused with the product's security monitoring pages: this is about watching the
application itself.

## What exists

| Signal | Where | Status |
|---|---|---|
| Liveness | `GET /health` — 200 without authentication | `IMPLEMENTED` |
| Authentication activity | `audit_logs` | `IMPLEMENTED` |
| Errors | `php_error_log` (system file) | `PARTIAL` |
| Requests | Apache `access_log` | `PARTIAL` |

`/health` only proves that PHP answered: it does **not** check the database (although a failed
connection would break the request during bootstrap, so a 500 there does indicate a database
problem).

## What does not exist

| Missing | Consequence | Status |
|---|---|---|
| Metrics export (Prometheus or similar) | No latency, error-rate or throughput history | `PLANNED` |
| Alerting | Nobody is told when the application fails | `PLANNED` |
| Structured application log | Errors cannot be correlated except by timestamp | `PLANNED` |
| Uptime probe | — | `PLANNED` |
| Database monitoring | Growth and slow queries are invisible | `PLANNED` |

## Minimum worth adding first (`PROPOSED`)

1. An external probe on `/health`, alerting on failure.
2. A daily count of `auth.login.failure` and `auth.login.throttled` per address, to notice
   guessing campaigns.
3. Table growth monitoring, starting with `audit_logs`.
