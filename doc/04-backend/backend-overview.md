# Backend overview

PHP 8.2, no framework, one Composer dependency (`vlucas/phpdotenv`). Everything is explicit:
routes, wiring, SQL.

| Item | Value |
|---|---|
| Entry point | `backend/public/index.php` (the only file Apache serves in the backend) |
| Namespace | `CyberGuard\Campus\`, PSR-4 on `backend/src` |
| Classes | 39, all `final`, `declare(strict_types=1)` |
| HTTP surface | 10 routes — see [08-api/endpoints.md](../08-api/endpoints.md) |
| Database access | PDO, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false`, prepared statements only |
| Sessions | PHP sessions, configured in `SessionManager` |
| Tests | `backend/tests`, 21 suites |

## Layer rules

```text
Controller   HTTP only: read the request, pick a status code, emit JSON
   ↓
Service      business rules, no HTTP, no SQL
   ↓
Repository   SQL only, returns models or arrays
   ↓
Model        read-only value object, exposes toArray() for the API
```

A controller never writes SQL; a repository never emits a response; a service never touches
`$_SERVER`. `HttpRequest` is the only object that reads superglobals, in `fromGlobals()`.

## Bootstrap sequence (`backend/public/index.php`)

1. `require vendor/autoload.php`
2. `header_remove('X-Powered-By')`
3. `Environment::load(projectRoot)` — reads `.env` (immutable)
4. `HttpRequest::fromGlobals()`
5. `Database::connection()` — one PDO for the request
6. Build repositories → services → controllers → middleware
7. Register the 10 routes with their middleware
8. `Router::dispatch($request)`

Consequence worth knowing: the database connection is opened for every request, including
`/health` and unauthenticated 401 answers.

## Related pages

- [directory-structure.md](directory-structure.md) — what lives where
- [services.md](services.md) — business rules per service
- [authentication.md](authentication.md) and [authorization.md](authorization.md)
- [error-handling.md](error-handling.md) — status codes and failure behaviour
- [database.md](database.md) — access layer (schema is in [09-database](../09-database/schema.md))
