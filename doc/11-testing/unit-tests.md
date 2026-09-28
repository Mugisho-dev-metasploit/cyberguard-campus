# Unit tests

There is **no unit-testing framework** in the repository: no PHPUnit, no Pest, no `phpunit.xml`.
The closest equivalents are the suites that exercise classes in process, without HTTP.

| Suite | What it isolates | Technique |
|---|---|---|
| `SessionExpirationTest` | `SessionManager` lifetime rules | In-memory SQLite for users, injected clock, cookie simulated |
| `LoginTimingTest` | `AuthenticationService` cost per failure case | Real database, medians over interleaved rounds |
| `PasswordRehashTest` | Hash policy and the rehash race | Real database plus `TkThrowingPdo` and a racing PDO double |
| `SessionEstablishmentTest` | `SessionManager` failure paths | A session handler whose read or destroy fails on demand |
| `IncidentWorkflowTest` | `IncidentService` rules | Real database, every status pair against both roles |

Why there is no framework: the project has one Composer dependency and the suites need real
concurrency, real HTTP and real database behaviour, which a unit framework does not provide.
Adding PHPUnit for the pure-logic parts (validation, derivations) is a reasonable future step
(`PROPOSED`).

## Pure functions that would suit unit tests

- `monitoring-data.js` (frontend) — pure derivations, currently untested.
- `format.js` (frontend) — formatting helpers, currently untested.
- `LoginThrottle::delay()` — already checked inside `LoginThrottleTest`.
