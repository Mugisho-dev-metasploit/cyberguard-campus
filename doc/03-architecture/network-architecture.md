# Network architecture

## Observed deployment (development laboratory)

```mermaid
flowchart LR
    Client["Browser / curl"] -->|"TCP 80 (HTTP)"| Apache
    Client -->|"TCP 443 (HTTPS, same document root)"| Apache
    Apache["Apache 2.4.58 (XAMPP)<br/>DocumentRoot /opt/lampp/htdocs"] --> PHP["mod_php 8.2.12"]
    PHP -->|"TCP 127.0.0.1:3306"| DB[("MariaDB 11.8.6")]
```

| Property | Value | Source |
|---|---|---|
| HTTP listener | `*:80` | `httpd.conf` |
| HTTPS listener | `*:443`, same document root | XAMPP SSL virtual host |
| Database listener | `127.0.0.1:3306` only | MariaDB configuration |
| Application URL | `http://<host>/cyberguard-campus/frontend/…` and `…/backend/public/index.php/…` | `.htaccess`, `frontend/assets/js/api.js` |
| TLS in use | No; the laboratory runs over plain HTTP | See [06-security/network-security.md](../06-security/network-security.md) |

Other applications share the same Apache document root; the project protects itself with its own
`.htaccess` rules rather than with a dedicated virtual host.

## Segmentation

The repository contains no network design: `infrastructure/` is a set of empty directories. Any
description of VLANs, span ports, sensor placement or firewall rules would be invention. What
the application needs is modest and can be stated:

| Flow | Direction | Port | Status |
|---|---|---|---|
| Analyst → application | inbound | 443 (should be), 80 (today) | `IMPLEMENTED` (HTTP), HTTPS `PLANNED` |
| Application → database | loopback | 3306 | `IMPLEMENTED` |
| Sensors → application | inbound | none defined | `PLANNED` |
| Application → notification services | outbound | none | `PLANNED` |

## Target topology (`PROPOSED`)

```mermaid
flowchart TD
    subgraph Customer["Customer network — PLANNED"]
        Users["Users / assets"] --> SW["Switching"]
        SW --> FW["Firewall"]
        SW --> TAP["TAP / mirror port"]
        TAP --> Sensor["IDS sensor"]
    end
    FW -.-> Collector["Collector"]
    Sensor -.-> Collector
    Collector -.-> App["CYBERGUARD API"]
    App --> DB[("Database")]
```

Everything in this second diagram is `PLANNED`; see
[07-network-monitoring/network-topology.md](../07-network-monitoring/network-topology.md).
