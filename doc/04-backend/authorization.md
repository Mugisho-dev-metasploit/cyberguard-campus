# Authorization (backend)

Two layers: the route's allowed roles, and rules inside the service for specific actions.

## Route level (`Middleware/AuthorizationMiddleware.php`)

Each protected route declares the roles it accepts. The middleware runs after
`AuthenticationMiddleware`, so the role it reads has just been refreshed from the database.

| Route | Roles |
|---|---|
| `GET /api/incidents` | viewer, analyst, admin |
| `GET /api/incidents/{id}` | viewer, analyst, admin |
| `PATCH /api/incidents/{id}` | analyst, admin |
| `GET /api/events` | viewer, analyst, admin |
| `GET /api/alerts` | viewer, analyst, admin |
| `GET /api/devices` | viewer, analyst, admin |
| `GET /api/metrics` | viewer, analyst, admin |

Failure answers `403 {"success":false,"message":"Insufficient permissions."}`. A missing or
invalid session answers 401 before the role is considered, so 403 always means "authenticated
but not allowed".

## Action level (`Services/IncidentService.php`)

`STATUS_PERMISSIONS = ['closed' => ['admin']]`: only an administrator may move an incident to
`closed`. The service throws `AuthorizationException`, which the controller maps to 403.

The actor identity used for this check — and written to `incident_history` — comes from the
session (`SessionManager::userId()`, `role()`), never from the request body. Sending
`{"role":"admin"}` is rejected as an unknown field (422).

## Object-level access

There is no per-record ownership: any authenticated role may read every incident, event, alert
and device. Restricting rows to a team, an organisation or an assignee would require a tenant or
ownership model (`PLANNED`, see [02-product/saas-model.md](../02-product/saas-model.md)).

`AuthorizationApiTest` covers the matrix above over HTTP.
