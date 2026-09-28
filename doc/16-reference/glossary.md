# Glossary

## Product terms

| Term | Definition |
|---|---|
| **Event** | A raw security record from a source, with its network 5-tuple. Stored in `events`; never created by the application today |
| **Alert** | A qualified finding with severity and status, optionally linked to an event and a device |
| **Incident** | A case with a lifecycle (`open` → `acknowledged` → `investigating` → `contained` → `resolved` → `closed`), an owner and a history |
| **Device** | A network asset in the inventory: router, switch, firewall, sensor, server, workstation, other |
| **Severity** | 1 to 4 on events, alerts and incidents; 4 is critical |
| **Priority** | Incident-only field: `low`, `medium`, `high`, `critical` |
| **Incident history** | One row per accepted incident change: actor, previous and new values |
| **Audit log** | Record of an authentication event (`auth.login.success`, `auth.login.failure`, `auth.login.throttled`, `auth.logout`, `auth.session.revoked`) |
| **Throttle bucket** | Counter that slows repeated sign-in failures, keyed by a digest of the identifier (and the address) |
| **Reference hash** | A bcrypt hash used when no active account matches, so every failed sign-in costs one verification |

## Security terms

| Term | Definition |
|---|---|
| **SOC** | Security Operations Centre: the team that watches and responds |
| **SIEM** | Security Information and Event Management: aggregates and correlates events; `PLANNED` here |
| **IDS / IPS** | Intrusion Detection / Prevention System, such as Suricata; `PLANNED` here |
| **Firewall** | Filters traffic between segments; can be recorded as a device |
| **Sensor** | Probe producing security records; `PLANNED` here |
| **RBAC** | Role-Based Access Control: `viewer`, `analyst`, `admin` |
| **CSRF** | Cross-Site Request Forgery: a foreign site making a victim's browser perform an action. Sign-in is protected by requiring JSON and checking `Origin` |
| **XSS** | Cross-Site Scripting: injecting script into a page. The interface builds DOM nodes, never HTML strings |
| **IDOR / BOLA** | Accessing another user's object by guessing its identifier. Not applicable today: every role may read every record by design |
| **SQL injection** | Injecting SQL through input; prevented by prepared statements and column allow-lists |
| **Session fixation** | Reusing a session identifier chosen before sign-in; prevented by regeneration |
| **Brute force** | Guessing credentials; slowed by progressive throttling |
| **Account enumeration** | Discovering which accounts exist, through responses or timing; equalised here |
| **bcrypt** | Password hashing function; cost 12 in this project; uses only the first 72 bytes |
| **HSTS** | HTTP Strict Transport Security; `PLANNED` |
| **Tenant** | A customer boundary in a multi-tenant product; not implemented |

## Technical terms

| Term | Definition |
|---|---|
| **Front controller** | The single PHP entry point (`backend/public/index.php`) |
| **Middleware** | A function wrapping a route handler; used for authentication and authorization |
| **Repository** | The only layer that writes SQL |
| **Migration** | A numbered SQL file creating a table; applied by hand |
| **ES module** | JavaScript module loaded with `type="module"`, no bundler |
| **Suite** | One standalone PHP test script in `backend/tests` |
