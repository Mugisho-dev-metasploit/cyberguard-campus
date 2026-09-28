# Project overview

CYBERGUARD CAMPUS is a web application for security operations: it stores security events,
alerts, network devices and incidents, exposes them through a REST API, and provides a browser
interface for analysts to read them and to drive incidents through a fixed lifecycle.

## What the repository contains

| Directory | Contents | Status |
|---|---|---|
| `backend/` | PHP API: front controller, router, middleware, controllers, services, repositories, models, SQL migrations, test suites | `IMPLEMENTED` |
| `frontend/` | Static web interface: 8 HTML pages, ES modules, CSS, fonts, images | `IMPLEMENTED` |
| `docs/` | `security/` holds the APP-07.4 hardening document; other subdirectories are empty | `PARTIAL` |
| `doc/` | This documentation | `IMPLEMENTED` |
| `infrastructure/` | `eve-ng/`, `grafana/`, `pfsense/`, `siem/`, `suricata/`, `zabbix/` — all empty | `PLANNED` |
| `scripts/` | Empty | `PLANNED` |
| `tests/` | `application/`, `detection/`, `integration/`, `network/`, `security/` — all empty; the real tests live in `backend/tests/` | `PLANNED` |

## Runtime

Served by Apache with `mod_php` from the XAMPP stack; the project directory sits inside the
Apache document root and is protected by `.htaccess` rules (see
[06-security/network-security.md](../06-security/network-security.md)).

| Layer | Technology | Version observed |
|---|---|---|
| Web server | Apache | 2.4.58 (XAMPP) |
| Language | PHP | 8.2.12 |
| Database | MariaDB | 11.8.6 |
| Dependency | `vlucas/phpdotenv` | `^5.7` (the only Composer dependency) |
| Frontend | Vanilla ES modules, no framework, no build step | — |

## What the application does today

- Signs users in and out, with sessions, roles and a set of hardened authentication controls.
- Serves read endpoints for incidents, events, alerts, devices and summary metrics.
- Lets an analyst or an administrator move an incident forward through its lifecycle, with a
  full history and an audit trail.
- Renders all of this in a dashboard and five operational pages.

## What it does not do yet

- It does not collect anything by itself: no agent, no sensor, no syslog receiver, no API for
  writing events or alerts. Records reach the database only through SQL.
- It is single-tenant: there is no organization or customer entity.
- There is no packaging (no Docker), no CI, and no deployment automation.

See [roadmap.md](roadmap.md) for the ordered view.
