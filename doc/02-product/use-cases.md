# Use cases

Flows an operator can complete today, end to end, with the code in the repository.

## UC-1 — Sign in and get the picture

1. Open `/frontend/welcome.html`, then sign in on `/frontend/login.html`.
2. The dashboard loads `GET /api/metrics` and `GET /api/alerts` and shows counters and alert
   activity for the last 14 days.

Status: `IMPLEMENTED`.

## UC-2 — Triage an incident

1. Open **Incidents**; the list comes from `GET /api/incidents`.
2. Open one; the detail and its history come from `GET /api/incidents/{id}`.
3. As `analyst`, move it one step forward (`open → acknowledged → investigating → contained →
   resolved`) with `PATCH /api/incidents/{id}`; every change is written to `incident_history`.
4. As `admin`, close it (`resolved → closed`).

Status: `IMPLEMENTED`. A `viewer` can read but every write answers 403.

## UC-3 — Look at the network inventory

Open **Devices**: `GET /api/devices` returns the inventory with recorded status and last
observation; the page shows how stale each observation is.

Status: `IMPLEMENTED`.

## UC-4 — Study activity over time

Open **Monitoring**: the page loads metrics, events, alerts and devices, then derives counts per
day, per hour and per environment in the browser (`monitoring-data.js`). No value is estimated.

Status: `IMPLEMENTED`.

## UC-5 — Investigate a sign-in problem

Query `audit_logs` for `auth.login.failure`, `auth.login.throttled` or `auth.session.revoked`.
The trail records the account (when known), the client address and the user agent, never the
identifier typed or the password.

Status: `IMPLEMENTED` (SQL only — no interface, `PLANNED`).

## Flows that cannot be completed today

| Flow | Missing |
|---|---|
| An IDS alert arrives and raises an incident | Ingestion and detection (`PLANNED`) |
| Create an incident manually | No create endpoint (`PLANNED`) |
| Acknowledge an alert | Alerts are read-only (`PLANNED`) |
| Add a user | No user management (`PLANNED`) |
| Export a report | No reporting (`PLANNED`) |
