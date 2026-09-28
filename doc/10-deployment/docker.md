# Docker

**Status: `PLANNED`. The repository contains no Dockerfile, no `docker-compose.yml`, and no
container configuration of any kind** (verified across the whole tree).

The application runs on a classic LAMP stack, installed on the host.

## What a container image would need

| Concern | Detail |
|---|---|
| Base | PHP 8.2 with `pdo_mysql`, `mbstring`; Apache or PHP-FPM + nginx |
| Document root | `frontend/` and `backend/public/` only; the exposure rules must be reproduced in the server configuration, since `.htaccess` may not be read |
| Configuration | `.env` injected as environment variables (the code reads `$_ENV`, `$_SERVER` and `getenv`) |
| Sessions | A writable, private session directory per container; sticky sessions or shared storage if more than one replica |
| Database | A separate service; the application opens one connection per request |
| Migrations | No runner exists: applying them would be a manual or scripted step |
| Health check | `GET /health` answers 200 without authentication |

## Warning for a containerised deployment

The current session storage assumption (local files) prevents running more than one replica
without shared session storage. See [../03-architecture/scalability.md](../03-architecture/scalability.md).
