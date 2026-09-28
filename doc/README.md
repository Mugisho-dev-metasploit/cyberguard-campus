# CYBERGUARD CAMPUS — documentation

CYBERGUARD CAMPUS is a security monitoring and incident management application: a PHP REST API,
a MariaDB database and a browser interface where an analyst reads security events, alerts and
network devices, and drives incidents through their lifecycle.

This documentation describes **the repository as it is**. Anything that is only intended is
labelled, never described as working software.

| Status | Meaning |
|---|---|
| `IMPLEMENTED` | Present in the repository and verifiable in the code |
| `PARTIAL` | Partly built; the gap is stated |
| `PLANNED` | Intended, nothing in the repository yet |
| `PROPOSED` | Idea only, no decision taken |
| `UNKNOWN` | Cannot be confirmed from the repository |

## Current state

| Item | Value |
|---|---|
| Version | No version is declared anywhere in the repository (`UNKNOWN`) |
| Last commit analysed | `54bb141 test(security): validate authorization and incident input controls` |
| Runtime | PHP 8.2 (XAMPP, `mod_php`), MariaDB 11.8, Apache 2.4 |
| Backend | 39 PHP classes under `backend/src`, PSR-4 `CyberGuard\Campus\` |
| Frontend | 8 HTML pages, 16 ES modules, no build step, no framework |
| Database | 8 tables, 8 SQL migrations |
| Tests | 21 suites, 648 checks (`backend/tests/run.php`) |
| Data collection from network devices | **Not implemented** — see [07-network-monitoring](07-network-monitoring/monitoring-overview.md) |
| Multi-tenant / SaaS | **Not implemented** — see [02-product/saas-model.md](02-product/saas-model.md) |

The application reads and manages data that is already in its database. Nothing in the
repository collects events from a firewall, an IDS or a sensor: the ingestion side of the
pipeline is `PLANNED`.

## Main components

| Component | Where | Status |
|---|---|---|
| REST API (front controller, router, middleware, controllers) | `backend/public/index.php`, `backend/src` | `IMPLEMENTED` |
| Authentication, sessions, RBAC | `backend/src/Services`, `backend/src/Core/SessionManager.php`, `backend/src/Middleware` | `IMPLEMENTED` |
| Incident management (state machine, history, audit) | `backend/src/Services/IncidentService.php` | `IMPLEMENTED` |
| Read endpoints for events, alerts, devices, metrics | `backend/src/Controllers` | `IMPLEMENTED` |
| Web interface (dashboard, events, alerts, incidents, devices, monitoring) | `frontend/` | `IMPLEMENTED` |
| Infrastructure integrations (Suricata, pfSense, Zabbix, Grafana, EVE-NG, SIEM) | `infrastructure/` (empty directories) | `PLANNED` |
| Operational scripts | `scripts/` (empty) | `PLANNED` |

## How to navigate

| You want | Go to |
|---|---|
| What the product is and where it is going | [01-overview](01-overview/project-overview.md), [02-product](02-product/product-overview.md) |
| How the pieces fit together, with diagrams | [03-architecture](03-architecture/system-architecture.md) |
| Backend code, services, error handling | [04-backend](04-backend/backend-overview.md) |
| Web interface, pages and their API calls | [05-frontend](05-frontend/frontend-overview.md) |
| Security controls, threat model, audit trail | [06-security](06-security/security-overview.md) |
| Network security stack (what exists, what does not) | [07-network-monitoring](07-network-monitoring/monitoring-overview.md) |
| API reference with examples | [08-api](08-api/api-overview.md) |
| Tables, relationships, migrations | [09-database](09-database/schema.md) |
| Install, run, deploy | [10-deployment](10-deployment/development.md) |
| Tests and how to run them | [11-testing](11-testing/testing-strategy.md) |
| Day-to-day operation | [12-operations](12-operations/operations.md) |
| Working on the code | [13-development](13-development/setup.md) |
| Milestones and history | [14-project-management](14-project-management/changelog.md) |
| Business framing | [15-business](15-business/business-model.md) |
| Glossary, variables, commands, ports | [16-reference](16-reference/glossary.md) |

## Documentation status

**Documented from the code**: architecture, backend, frontend, API, database, security controls,
tests, development setup, environment variables, ports, commands, glossary.

**Partially documented**: deployment (no production deployment exists yet — requirements only),
operations (no runbooks in the repository), project management (team and milestones are not
recorded in the repository).

**Not documented because not implemented**: sensors and collectors, SIEM correlation, firewall
and IDS/IPS integration, Zabbix, Grafana, Wireshark workflows, webhooks, multi-tenancy,
automation (n8n), AI features. Each has a file explaining what exists today and what
integrating it would require.

## Related documents in the repository

- `README.md` (repository root) — short project summary.
- `backend/tests/README.md` — the test suites, how to run them and the data rules.
- `docs/security/APP-07.4-authentication-hardening.md` — authentication hardening status and
  production requirements. The older `docs/` tree is otherwise empty; this `doc/` tree is the
  documentation entry point.
