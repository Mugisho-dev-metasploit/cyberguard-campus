# Dependencies

## Backend (Composer)

| Package | Version constraint | Role |
|---|---|---|
| `vlucas/phpdotenv` | `^5.7` | Reads `.env` into `$_ENV` (immutable mode) |

That is the entire dependency list (`backend/composer.json`). Autoloading is PSR-4 on
`CyberGuard\Campus\` → `backend/src`.

## Frontend

**None.** No `package.json`, no `node_modules`, no CDN reference: the interface is plain ES
modules, CSS and self-hosted fonts (IBM Plex, SIL OFL licence, `frontend/assets/fonts/OFL.txt`).

## Runtime

| Component | Version observed | Required by |
|---|---|---|
| PHP | 8.2.12 | `#[\SensitiveParameter]` needs PHP ≥ 8.2 |
| PHP extensions | `pdo_mysql`, `mbstring`; `pdo_sqlite` and `curl` for the tests | Application and suites |
| MariaDB | 11.8.6 | `WEIGHT_STRING`, `SHA2`, check constraints, `JSON` columns |
| Apache | 2.4.58 with `mod_php` | `.htaccess` exposure policy (needs `AllowOverride All`) |

## Not used

No container runtime, no Node.js, no Python, no queue, no cache server, no external API. Any
tool named in the project vision (Suricata, pfSense, Zabbix, Grafana, EVE-NG, n8n) is absent
from the repository.

## Update policy

`composer update` on a security advisory, then the full test suite. With one dependency, the
supply-chain surface is deliberately small; keep it that way unless a need is proven.
