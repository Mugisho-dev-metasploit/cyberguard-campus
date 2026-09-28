# API authentication

## How a client authenticates

1. `POST /login` with a JSON body `{"identifier": "...", "password": "..."}`.
   `identifier` is a username **or** an email address.
2. The response sets a session cookie (`SESSION_NAME`, default `cyberguard_session`).
3. Every later request sends that cookie; the browser does it automatically, a CLI client needs
   a cookie jar.
4. `POST /logout` ends the session server-side.

There is no API key, no bearer token and no machine account: only browser-style sessions
(`PLANNED` for integrations, see
[03-architecture/integration-architecture.md](../03-architecture/integration-architecture.md)).

## Requirements on `POST /login`

| Requirement | Enforcement |
|---|---|
| `Content-Type: application/json` | Anything else → 415, even with valid credentials |
| `Origin`, when present, equals the server origin | Otherwise 403 |
| `identifier` ≤ 254 characters (after trim), `password` ≤ 1024 bytes | Otherwise 401 |
| Not throttled | Otherwise 429 with `Retry-After` |

The JSON requirement is what stops a cross-site HTML form from signing a victim in; see
[06-security/api-security.md](../06-security/api-security.md).

## Session properties

| Property | Value |
|---|---|
| Lifetime | 6 hours absolute from sign-in; activity does not extend it |
| Cookie flags | `HttpOnly`, `SameSite=Lax`, `Path=/`, `Secure` when `APP_ENV=production` |
| Revocation | The account is re-read on every protected request; disabled, locked or deleted → 401 and the session is destroyed |
| Role | Re-read from the database on every request |

## Responses a client must handle

| Code | Meaning | Client action |
|---|---|---|
| 401 on `/api/*` | No session, expired, or revoked | Send the user back to sign-in |
| 403 | Role not allowed | Hide or disable the action |
| 429 | Too many sign-in attempts | Wait `Retry-After` seconds |
| 415 / 400 | Malformed request | Fix the request |
| 500 | Server-side failure | Retry later; the body carries no detail |
