# Vision

## The problem

An organisation with an IP network produces security signal in many places — firewall logs,
IDS/IPS alerts, device state, system logs — and that signal is usually scattered across tools
that do not share a vocabulary. Three practical consequences:

- **No single view.** Answering "what happened on the network in the last hour?" means opening
  several consoles.
- **Alerts without follow-through.** An alert is seen, then lost: no owner, no state, no record
  of what was decided.
- **No defensible trail.** After an incident, who did what and when is hard to reconstruct.

## The intended answer

One place where signal becomes an alert, an alert becomes an incident, and every incident
carries its own history: status, owner, timestamps, decisions.

```text
Network infrastructure
        ↓
Collection (sensors, firewall, IDS/IPS)         ← PLANNED
        ↓
Normalisation and storage (events)              ← storage IMPLEMENTED, ingestion PLANNED
        ↓
Detection and correlation                       ← PLANNED
        ↓
Alerts                                          ← storage and reading IMPLEMENTED
        ↓
Incident management                             ← IMPLEMENTED
        ↓
Dashboard, reports, automation                  ← dashboard IMPLEMENTED, the rest PLANNED
```

## Where the project stands against that vision

The **right-hand half** of the pipeline is built: storage, the API, the incident lifecycle, the
interface, and a hardened authentication layer. The **left-hand half** — everything that makes
data arrive on its own — is not started: `infrastructure/` contains only empty directories.

## Beyond the campus

The name comes from the first target, a university network, but nothing in the data model is
specific to a campus: devices, events, alerts and incidents describe any IP network. Serving
several organisations from one installation would need a tenant boundary that does not exist
today — see [02-product/saas-model.md](../02-product/saas-model.md).
