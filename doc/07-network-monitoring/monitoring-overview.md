# Network monitoring — overview

**Summary: the application stores and displays network security records; it does not collect
them.** Every integration named in this section is `PLANNED`.

## Evidence

| Check | Result |
|---|---|
| `infrastructure/eve-ng`, `grafana`, `pfsense`, `siem`, `suricata`, `zabbix` | Empty directories |
| `scripts/` | Empty |
| Outbound HTTP client, socket, syslog listener in `backend/src` | None |
| Write endpoint for events or alerts | None |
| Parser for EVE JSON, syslog, NetFlow | None |
| Scheduler or worker | None |

## What exists that is monitoring-shaped

| Capability | Detail | Status |
|---|---|---|
| Event model | Full network 5-tuple, source, signature, category, raw and normalised payloads | `IMPLEMENTED` (storage) |
| Alert model | Severity, status, links to event and device | `IMPLEMENTED` (storage) |
| Device inventory | Type, environment, recorded status, last observation | `IMPLEMENTED` |
| Operational views | Dashboard counters, alert activity, per-day/per-hour/per-environment derivations | `IMPLEMENTED` |
| Incident handling | Full lifecycle with history | `IMPLEMENTED` |
| Collection, detection, correlation | — | `PLANNED` |

## Intended chain, with the boundary marked

```text
Network            PLANNED
  ↓
Firewall           PLANNED   → doc: firewall-integration.md
  ↓
IDS / IPS          PLANNED   → doc: ids-ips.md
  ↓
Sensors            PLANNED   → doc: sensors.md
  ↓
Event collection   PLANNED   → doc: ../03-architecture/event-flow.md
  ↓
SIEM / correlation PLANNED   → doc: siem.md
  ↓
Alert              IMPLEMENTED (storage and reading)
  ↓
Incident           IMPLEMENTED
  ↓
Response           PARTIAL (manual, inside the application)
```

## Reading the rest of this section

Each file states what exists, what integrating the tool would require, and where it would
attach. None of them describes a working integration, because none exists.
