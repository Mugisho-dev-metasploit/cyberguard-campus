# Data retention

## What the application deletes by itself

| Data | Rule | Where |
|---|---|---|
| Throttle buckets | Rows idle for more than 2 hours are purged (batch of 100) during a sign-in attempt | `LoginThrottle::PURGE_AFTER_IDLE_SECONDS` |
| Sessions | PHP garbage collection on the session store; the application sets `gc_maxlifetime` to 21600 s | `SessionManager` |

Nothing else is ever deleted by the code.

## What grows without bound

| Data | Growth driver | Risk |
|---|---|---|
| `audit_logs` | Every authentication event | Unbounded over time; failures are rate-limited by bcrypt cost and throttled events are de-duplicated per address per minute, but nothing purges old rows |
| `events`, `alerts` | Would grow with ingestion (`PLANNED`) | The schema is ready; no retention policy exists |
| `incidents`, `incident_history` | Operational volume | Small by nature; history is worth keeping |
| `php_error_log` | PHP warnings and fatals | System file, rotation is an infrastructure concern |

## Recommended policy (`PROPOSED`, nothing implemented)

| Data | Suggested retention | Note |
|---|---|---|
| `audit_logs` (`auth.*`) | 365 days | Supported by the `(action, created_at)` index; deletion should be a scheduled job, not a request-time purge |
| `events` | 30–90 days once ingestion exists | Raw payloads dominate the volume |
| `alerts` | 1 year | Small compared with events |
| `incidents` and their history | Keep | The record of what was decided |

Any retention job must be reviewed against the audit requirement: deleting authentication events
removes evidence, so it is a policy decision, not a technical one.
