# Integration tests

Most suites are integration tests: they run the real front controller over HTTP against the real
database.

| Suite | Purpose | Notes |
|---|---|---|
| `IncidentApiTest` | The API over HTTP for the incident endpoints: roles, 401/404/400, injection attempts, response shape, sensitive-data scan | Sessions are planted in a private store |
| `IncidentWorkflowTest` | State machine: 36 status combinations × analyst/admin, close reserved to admin, history, lifecycle timestamps, rollback at every write step | Real database |
| `IncidentConcurrencyTest` | Row lock with two real processes: stale transition refused, chained transitions, duplicate transition as a no-op | Two PHP processes |
| `IncidentErrorHandlingTest` | Controller error responses: simulated internal failures → generic 500, 404, 422, 400, 401 | `TkThrowingPdo` |
| `IncidentInputValidationTest` | Input bounds and validation on the incident update | |
| `AuthorizationApiTest` | The role matrix over HTTP for every protected route | |
| `SessionRevocationTest` | Account disabled, locked, deleted or soft-deleted → 401 and session destroyed; role changes applied on the next request | Private server |
| `LogoutTest` | `POST /logout`: server-side destruction, cookie expiry, idempotence, method handling | Private server |
| `AuthenticationAuditTest` | Events written to `audit_logs`, including de-duplication and failure parity | Private server |

Common pattern: start `php -S` on a free port with `-t backend/public` and a private
`session.save_path`, drive it with `file_get_contents` or cURL, then clean up.
