# System architecture

## What runs today

```mermaid
flowchart LR
    Browser["Browser<br/>(analyst)"]
    Apache["Apache 2.4 + mod_php 8.2"]
    Front["Static interface<br/>frontend/"]
    FC["Front controller<br/>backend/public/index.php"]
    App["Router → Middleware → Controllers<br/>Services → Repositories"]
    DB[("MariaDB 11.8<br/>cyberguard")]

    Browser -->|HTTP/1.1| Apache
    Apache --> Front
    Apache --> FC
    FC --> App
    App -->|PDO, prepared statements| DB
    Front -->|fetch, same origin| FC
```

Everything runs on one host. Apache serves the static interface and the single PHP entry point;
PHP talks to MariaDB on `127.0.0.1:3306`. There is no queue, no cache, no external service.

## Intended pipeline, with today's boundary

```mermaid
flowchart LR
    subgraph Planned["PLANNED — nothing in the repository"]
        Net["Customer network"] --> FW["Firewall / pfSense"]
        FW --> IDS["IDS-IPS / Suricata"]
        IDS --> Collect["Collection / agents"]
        Collect --> Norm["Normalisation"]
        Norm --> Detect["Detection / correlation"]
    end
    subgraph Implemented["IMPLEMENTED"]
        DB[("events, alerts, devices,<br/>incidents, audit_logs")]
        API["REST API"]
        UI["Web interface"]
    end
    Detect -.->|no code today| DB
    DB --> API --> UI
```

The dotted arrow is the gap: records reach the database by SQL only.

## Layers

| Layer | Responsibility | Code |
|---|---|---|
| Transport | TLS (absent in the laboratory), routing of `/backend/public/index.php/...` | Apache, `.htaccess` |
| Entry point | Autoload, environment, dependency wiring, route table | `backend/public/index.php` |
| Routing | Method + path match, middleware pipeline, 404/405 | `Routing/Router.php` |
| Middleware | Authentication (session + account), authorization (role) | `Middleware/` |
| Controllers | HTTP shape: read input, choose status code, emit JSON | `Controllers/` |
| Services | Business rules: authentication, throttling, audit, incident state machine | `Services/` |
| Repositories | SQL, always prepared statements, mapping to models | `Repositories/` |
| Models | Read-only value objects | `Models/` |
| Storage | InnoDB tables with foreign keys and check constraints | `backend/database/migrations` |

Dependencies point downwards only; no repository calls a service, no service emits HTTP.

## Properties worth knowing

- **Stateless except for sessions**: session state lives in PHP session files, everything else
  in MariaDB. Horizontal scaling would need shared session storage — see
  [scalability.md](scalability.md).
- **One connection per request**, created in `Database::connection()` and reused in-process.
- **No background worker**: everything happens inside the request.
- **No cache layer**: every read hits the database.
