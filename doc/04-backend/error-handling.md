# Error handling

## Principles observed in the code

- Controllers catch `Throwable` and answer a generic message; internals never reach the client.
- `display_errors` is off in the server configuration, so an uncaught error produces an empty
  body, not a stack trace.
- Sensitive parameters (`$password`, `$identifier`, password hashes) are annotated
  `#[\SensitiveParameter]`, so a logged trace shows `Object(SensitiveParameterValue)`.
- Validation messages that reach the client come only from `IncidentService` and describe the
  rule, never the storage.

## Status codes used

| Code | When | Body message |
|---|---|---|
| 200 | Success | Endpoint-specific |
| 400 | Malformed incident id, or invalid JSON body on `PATCH` | `Invalid incident identifier.`, `Invalid JSON payload.` |
| 401 | No session, expired session, revoked account; or rejected credentials | `Authentication required.` / `Invalid credentials.` |
| 403 | Authenticated but role not allowed; cross-origin sign-in | `Insufficient permissions.` / `Cross-origin sign-in refused.` |
| 404 | Unknown route, or incident not found | `Route not found.` / `Incident not found.` |
| 405 | Known path, wrong method (with `Allow`) | `Method not allowed.` |
| 415 | Sign-in body not declared as JSON | `Unsupported content type.` |
| 422 | Field or workflow validation failure | Message from `IncidentService` |
| 429 | Sign-in throttled (with `Retry-After`) | `Too many sign-in attempts. Try again later.` |
| 500 | Internal failure (database, session establishment) | `Unable to …` |

## Failure behaviour worth knowing

| Situation | Result |
|---|---|
| Database unavailable | `Database::connection()` throws during bootstrap → uncaught → HTTP 500 with an empty body. There is no global exception handler (`PLANNED`) |
| Query fails inside a read endpoint | Controller catches it → 500 with a generic message and `data: []` |
| Incident update fails midway | Transaction rolled back; no partial write, no orphan history row |
| Session cannot be established at sign-in | 500 `Unable to sign in.`, no session, throttle counters kept |
| Audit write fails | Ignored; authentication outcome unchanged |
| Throttle reset fails after a successful sign-in | Ignored; the sign-in still succeeds |

## Gaps

- No structured application log: the only traces are PHP warnings and fatals in
  `php_error_log`, plus the authentication audit trail in the database.
- No error identifier returned to the client, so correlating a user report with a server-side
  trace relies on timestamps (`PLANNED`).
