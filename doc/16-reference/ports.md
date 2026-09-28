# Ports and services

Observed on the reference host.

| Port | Service | Protocol | Environment | Exposure | Why | Risk |
|---|---|---|---|---|---|---|
| 80 | Apache HTTP | TCP | development | All interfaces | Serves the interface and the API | **Credentials and session cookies travel in clear**; should redirect to 443 in production |
| 443 | Apache HTTPS | TCP | development | All interfaces | Same document root over TLS | Default XAMPP certificate; not used by the application's own URLs today |
| 3306 | MariaDB | TCP | development | `127.0.0.1` only | Application storage | Low while it stays on loopback; never expose it |

## Ports used only by tests

| Port | Use |
|---|---|
| Ephemeral (kernel-assigned) | Several suites start `php -S` on a free port to drive the real front controller |

## Ports the application never uses

No outbound port: the application opens no connection of its own — no mail, no webhook, no API
call. Any future integration would add flows to open explicitly.

## Recommendations

- Production: expose 443 only, redirect 80, keep 3306 off the network.
- Do not put another application on the same host without reading
  [../06-security/network-security.md](../06-security/network-security.md): session files are
  shared.
