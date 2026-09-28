# API overview

A small REST-ish JSON API: 10 routes, session authentication, one write endpoint.

| Property | Value |
|---|---|
| Base URL (laboratory) | `http://<host>/cyberguard-campus/backend/public/index.php` |
| Path style | Routes are appended to the entry script: `…/index.php/api/events` |
| Format | JSON in, JSON out (`application/json; charset=utf-8`) |
| Authentication | Session cookie, obtained from `POST /login` |
| Versioning | None (`PLANNED`) |
| Pagination, filtering, sorting | None (`PLANNED`) — every list returns the whole table |
| CORS | No CORS headers; the API is same-origin only |
| Rate limiting | Only on `POST /login` |
| OpenAPI document | None in the repository (`PLANNED`) |

## Envelope

Success:

```json
{ "success": true, "message": "Events retrieved successfully.", "data": [ ] }
```

Failure:

```json
{ "success": false, "message": "Authentication required." }
```

`data` is present on read endpoints (and on `PATCH /api/incidents/{id}`), absent on most errors,
and an empty array on list endpoints that failed internally.

## Routes at a glance

| Method | Path | Auth | Roles |
|---|---|---|---|
| GET | `/health` | no | — |
| POST | `/login` | no | — |
| POST | `/logout` | no (works with or without a session) | — |
| GET | `/api/incidents` | yes | viewer, analyst, admin |
| GET | `/api/incidents/{id}` | yes | viewer, analyst, admin |
| PATCH | `/api/incidents/{id}` | yes | analyst, admin |
| GET | `/api/events` | yes | viewer, analyst, admin |
| GET | `/api/alerts` | yes | viewer, analyst, admin |
| GET | `/api/devices` | yes | viewer, analyst, admin |
| GET | `/api/metrics` | yes | viewer, analyst, admin |

Details and examples: [endpoints.md](endpoints.md), [examples.md](examples.md).
Authentication mechanics: [authentication.md](authentication.md).
