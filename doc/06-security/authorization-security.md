# Authorization security

## Model

Role-based, three roles, enforced in two places: the route's allowed roles
(`AuthorizationMiddleware`) and one per-action rule (`IncidentService`: closing an incident
requires `admin`). The matrix is in [04-backend/authorization.md](../04-backend/authorization.md).

| Item | Value |
|---|---|
| Threat addressed | A user performing an action above their role |
| Location | `Middleware/AuthorizationMiddleware.php`, `Services/IncidentService.php` |
| Source of truth | The role in the database, refreshed on every request by `AuthenticationMiddleware` |
| Client influence | None: a `role` field in the body is rejected as an unknown field |
| Status | `IMPLEMENTED` |

## Properties verified by tests

- A `viewer` reading is allowed; a `viewer` writing gets 403 (`AuthorizationApiTest`).
- An `analyst` cannot close an incident; an `admin` can.
- A role changed in the database applies on the next request, without signing out.
- A session whose account became inactive is revoked rather than downgraded.

## Limitations

| Limitation | Consequence | Status |
|---|---|---|
| No object-level authorization | Every authenticated role reads every incident, alert, event and device | `PLANNED` |
| No tenant boundary | One installation serves one organisation | `PLANNED` |
| No delegation or team scoping | An assignee has no extra rights over their incidents | `PROPOSED` |
| No administrative interface | Roles are changed with SQL, which the audit trail does not record | `PLANNED` |

## Assumption

Authorization depends on authentication running first: every protected route declares
`AuthenticationMiddleware` before `AuthorizationMiddleware`. That ordering is a convention of
the route table in `backend/public/index.php`, not something the router enforces. A route added
without the first middleware would check a role that was never refreshed.
