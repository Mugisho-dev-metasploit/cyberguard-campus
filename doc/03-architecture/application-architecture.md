# Application architecture

## Request lifecycle

```mermaid
sequenceDiagram
    participant C as Client
    participant I as index.php
    participant R as Router
    participant MW as Middleware
    participant Ctrl as Controller
    participant Svc as Service
    participant Repo as Repository
    participant DB as MariaDB

    C->>I: HTTP request
    I->>I: autoload, Environment::load(.env), header_remove(X-Powered-By)
    I->>I: HttpRequest::fromGlobals()
    I->>I: build repositories, services, controllers, middleware
    I->>R: dispatch(request)
    R->>MW: pipeline (protected routes only)
    MW->>Ctrl: next()
    Ctrl->>Svc: business call
    Svc->>Repo: query / command
    Repo->>DB: prepared statement
    DB-->>Repo: rows
    Repo-->>Svc: models
    Svc-->>Ctrl: arrays
    Ctrl-->>C: HttpResponse::json(payload, status, headers)
```

Wiring is explicit in `backend/public/index.php`: no container, no service locator, no
reflection. Every dependency is passed to a constructor.

## Classes by layer

| Layer | Classes |
|---|---|
| Bootstrap | `Environment` |
| Core | `Application`, `HttpRequest`, `HttpResponse`, `SessionManager` |
| Routing | `Router` |
| Middleware | `AuthenticationMiddleware`, `AuthorizationMiddleware` |
| Controllers | `LoginController`, `IncidentController`, `EventController`, `AlertController`, `DeviceController`, `MetricsController` |
| Services | `AuthenticationService`, `AuthenticationResult`, `AuthenticationAudit`, `LoginThrottle`, `IncidentService`, `EventService`, `AlertService`, `DeviceService`, `MetricsService` |
| Repositories | `UserRepository`, `IncidentRepository`, `EventRepository`, `AlertRepository`, `DeviceRepository`, `MetricsRepository`, `AuditLogRepository`, `LoginThrottleRepository` |
| Models | `User`, `Incident`, `Event`, `Alert`, `Device` |
| Exceptions | `AuthenticationException`, `AuthorizationException` |
| Database | `Database` |

`Core/Application.php` only returns the product name; it is not a kernel.

## Conventions

- `declare(strict_types=1)` in every file; PSR-4 under `CyberGuard\Campus\`.
- Constructor property promotion, `private readonly` dependencies, `final class`.
- Every response goes through `HttpResponse::json()`: `{success, message}` plus `data` on reads.
- Controllers catch `Throwable` and answer a generic 500; they never leak internals.
- Sensitive parameters (`$password`, `$identifier`, hashes) carry `#[\SensitiveParameter]`.

## Extension points

| To add | Touch |
|---|---|
| A route | `backend/public/index.php` (route table + wiring) |
| A read endpoint | Controller + Service + Repository + model |
| A rule on incidents | `IncidentService` constants and validation |
| A new authentication control | `LoginController` (transport), `AuthenticationService` (credentials), `SessionManager` (session) |
