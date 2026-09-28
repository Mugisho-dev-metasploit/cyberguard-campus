# User roles

Three roles exist in the database (`users.role`: `admin`, `analyst`, `viewer`) and are enforced
by `AuthorizationMiddleware` on each route, plus one extra rule inside `IncidentService`.

| Capability | viewer | analyst | admin |
|---|:--:|:--:|:--:|
| Sign in, sign out | yes | yes | yes |
| Read incidents, events, alerts, devices, metrics | yes | yes | yes |
| Update an incident (`PATCH /api/incidents/{id}`) | no (403) | yes | yes |
| Move an incident to `closed` | no | no (403) | yes |

Notes:

- The role used for a decision is re-read from the database on every request; a role changed in
  the database applies to the next request, without signing out (`IMPLEMENTED`).
- An account must be `active` and not soft-deleted; otherwise the session is revoked on the next
  protected request.
- There is no user administration feature: accounts and roles are set directly in the database
  (`PLANNED`).

## Account states

| `users.status` | Can sign in | Existing session |
|---|:--:|---|
| `active` | yes | kept |
| `inactive` | no | revoked on the next protected request |
| `locked` | no | revoked on the next protected request |
| soft-deleted (`deleted_at` set) | no | revoked on the next protected request |

`locked` is never set by the application: throttling delays sign-in attempts instead of locking
accounts (see [06-security/authentication-security.md](../06-security/authentication-security.md)).
