# Infrastructure

## What is deployed today

One host running the XAMPP stack:

| Component | Detail |
|---|---|
| Apache 2.4.58 | Serves every application in `/opt/lampp/htdocs`, including this one |
| PHP 8.2.12 (`mod_php`) | Shared by all applications on the host |
| MariaDB 11.8.6 | Loopback only, one schema for this project |
| Session storage | `/opt/lampp/temp`, shared by every application on the host |
| Logs | `/opt/lampp/logs/access_log`, `error_log`, `php_error_log` |

The project directory sits inside the shared document root and protects itself with `.htaccess`
rules rather than with its own virtual host.

## Consequences of sharing the host

| Consequence | Impact | Mitigation |
|---|---|---|
| Other applications run as the same user | They can read and write this application's session files | Dedicated pool and user (`PLANNED`) |
| Their session garbage collection applies | A session can disappear before its 6-hour lifetime | Private `session.save_path` (`PLANNED`) |
| Server-wide settings (`ServerTokens`, `expose_php`) are shared | Cannot be tightened for this project alone | Own virtual host (`PLANNED`) |

## `infrastructure/` in the repository

Empty directories for `eve-ng`, `grafana`, `pfsense`, `siem`, `suricata` and `zabbix`. They
express an intention; they hold no configuration. See
[../07-network-monitoring/monitoring-overview.md](../07-network-monitoring/monitoring-overview.md).

## Target for a real deployment (`PROPOSED`)

```text
[ Reverse proxy / TLS ]  →  [ PHP-FPM pool, dedicated user ]  →  [ MariaDB, dedicated host or socket ]
        HSTS                    private session path                 backups, restricted account
```
