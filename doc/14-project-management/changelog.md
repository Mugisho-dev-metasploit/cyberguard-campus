# Changelog

Derived from Git. The project has no tags and no version numbers, so entries are grouped by
theme with their commits.

## Security hardening — 2026-09-21 → 2026-09-22

| Commit | Change | Impact |
|---|---|---|
| `54bb141` | Tests for authorization and incident input validation | No behaviour change |
| `fb5f298` | Closes APP-07.4: `Secure` cookie from any `APP_ENV` source, authentication audit trail, identifier redacted in traces | **Behaviour**: new audit rows; `Secure` cookie now also set when `APP_ENV` comes from the environment |
| `e8e51c7` | Login CSRF (JSON required, `Origin` checked), password rehash policy, 405 + `Allow`, `X-Powered-By` removed, sign-in form posts | **Breaking for clients**: non-JSON sign-in now answers 415; wrong method now answers 405 instead of 404 |
| `e104d55` | Session establishment checked; `Cache-Control: no-store` on authentication answers | **Behaviour**: a failed session establishment answers 500 instead of a false 200 |
| `d69d0c9` | Input bounds on sign-in (254 characters / 1024 bytes) | **Behaviour**: oversized input answers 401 instead of causing a 500 |
| `abd72cb` | Sign-in throttling, timing equalisation, secrets redacted in traces | **Behaviour**: repeated failures answer 429 with `Retry-After`; new table `login_throttle` (migration 008) |

## Session lifecycle — 2026-09-21

| Commit | Change |
|---|---|
| `6714ba9` | Frontend sign-out wired to the API |
| `0c4a252` | `POST /logout` with server-side destruction |
| `1a7324b` | Sessions revoked when an account becomes inactive, locked or deleted; role re-read from the database |

## Exposure — 2026-09-21

| Commit | Change |
|---|---|
| `efe35b9` | Only the interface and the API front controller are served; `.env` untracked; test tooling refuses any web SAPI |

## Application build — 2026-09-11 → 2026-09-21

| Commit | Change |
|---|---|
| `9792bca` | Security operations interface (APP-05) |
| `b16552c`, `24da659`, `bb44d32`, `b84bab5` | Read endpoints: metrics, devices, alerts, events |
| `be19416`, `2d7499e` | Incident update and read endpoints |
| `3f9dc13`, `c32b9e7` | Middleware pipeline, front controller and routing |
| `9277408`, `70af223`, `ac096ff` | Authentication service, database layer, user repository |
| `e2a3bf3`, `7e21fc2` | Backend architecture, project initialisation |

## Schema changes

| Migration | Introduced with |
|---|---|
| `001`–`007` | The initial build (users, devices, events, alerts, incidents, incident history, audit logs) |
| `008_create_login_throttle.sql` | Sign-in throttling (`abd72cb`) |

## Uncommitted at the time of writing

`README.md` carries a local edit that adds credentials in clear. It must not be committed as is
— see [../06-security/secrets-management.md](../06-security/secrets-management.md).
