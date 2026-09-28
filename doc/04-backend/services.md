# Services

Business rules live here. No service emits HTTP or writes SQL.

| Service | Responsibility | Notable rules |
|---|---|---|
| `AuthenticationService` | Verify credentials, upgrade hashes | One bcrypt verification per attempt; bounds (254 characters / 1024 bytes); policy bcrypt cost 12; only `active` accounts pass |
| `AuthenticationResult` | Outcome value object | `success(User)` / `failure()` |
| `LoginThrottle` | Slow repeated failures | Buckets `source_account` (5 free) and `account` (10 free); 30 s doubling to 15 min; reset after 1 h idle or on success; refused attempts are not counted |
| `AuthenticationAudit` | Record authentication events | Five actions; identical rows for every failure; throttled events at most once per address per minute; best effort |
| `IncidentService` | Incident rules | Field allow-list, value validation, one-step state machine, `closed` reserved to admin, lifecycle timestamps, history |
| `EventService`, `AlertService`, `DeviceService` | Map repository models to arrays | No filtering or transformation |
| `MetricsService` | Return the five counters | Pass-through to `MetricsRepository` |

## `IncidentService` in detail

- `ALLOWED_FIELDS`: `title`, `description`, `severity`, `status`, `priority`, `assigned_to`,
  `resolution`. Anything else → `InvalidArgumentException` → 422 naming the unknown fields.
- Value rules: non-empty `title`; `severity` integer 1–4; `priority` in
  `low|medium|high|critical`; `assigned_to` null or an existing user id; `description` and
  `resolution` string or null.
- `STATUS_TRANSITIONS`: `open → acknowledged → investigating → contained → resolved → closed`,
  one step at a time, `closed` final; the current status is read from the stored row under a
  row lock.
- `STATUS_PERMISSIONS`: `closed` requires `admin`; otherwise `AuthorizationException` → 403.
- Every accepted change writes `incident_history` with the actor from the session.

## `LoginThrottle` in detail

Keys are digests computed by the database from the identifier's collation weights, so `Admin`,
`ADMIN` and `ádmin` share one bucket. No identifier and no address is stored in clear. See
[06-security/authentication-security.md](../06-security/authentication-security.md).
