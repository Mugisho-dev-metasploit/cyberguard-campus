# API tests

The API contract is covered by the suites listed below; there is no separate contract-testing
tool and no OpenAPI document to validate against (`PLANNED`).

| Endpoint | Covered by | What is asserted |
|---|---|---|
| `GET /health` | `HttpSurfaceTest`, `WebExposureTest` | 200, JSON body, method handling |
| `POST /login` | `LoginCsrfTest`, `LoginInputValidationTest`, `LoginThrottleTest`, `LoginTimingTest`, `AuthenticationCacheHeadersTest`, `ProductionCookieTest`, `SessionEstablishmentTest`, `AuthenticationAuditTest` | Status codes, exact bodies, headers, cookie attributes, throttling, audit rows |
| `POST /logout` | `LogoutTest`, `AuthenticationCacheHeadersTest`, `AuthenticationAuditTest` | Destruction, cookie expiry, idempotence, headers, audit |
| `GET /api/incidents` | `IncidentApiTest`, `AuthorizationApiTest` | Shape, roles, ordering, no sensitive field |
| `GET /api/incidents/{id}` | `IncidentApiTest` | Detail + history contract, 400/404 |
| `PATCH /api/incidents/{id}` | `IncidentWorkflowTest`, `IncidentInputValidationTest`, `IncidentConcurrencyTest`, `IncidentErrorHandlingTest`, `AuthorizationApiTest` | Field allow-list, value rules, transitions, permissions, concurrency, error mapping |
| `GET /api/events`, `/api/alerts`, `/api/devices`, `/api/metrics` | `AuthorizationApiTest`, `SessionRevocationTest` | 401/403/200 per role, revocation |

## Checks applied across endpoints

- **No sensitive data in responses**: suites scan payloads for password, hash, email in the
  wrong place, session identifiers and canary values planted in `metadata`.
- **Error bodies are generic**: no SQLSTATE, no class name, no path.
- **Status-code discipline**: 400 vs 401 vs 403 vs 404 vs 405 vs 415 vs 422 vs 429 vs 500 are
  asserted, not assumed.
