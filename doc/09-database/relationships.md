# Relationships

## Foreign keys as created

| Child | Column | Parent | On delete | On update |
|---|---|---|---|---|
| events | device_id | devices.id | SET NULL | CASCADE |
| alerts | event_id | events.id | SET NULL | CASCADE |
| alerts | device_id | devices.id | SET NULL | CASCADE |
| alerts | assigned_to | users.id | SET NULL | CASCADE |
| incidents | alert_id | alerts.id | SET NULL | CASCADE |
| incidents | device_id | devices.id | SET NULL | CASCADE |
| incidents | assigned_to | users.id | SET NULL | CASCADE |
| incident_history | incident_id | incidents.id | CASCADE | CASCADE |
| incident_history | user_id | users.id | SET NULL | CASCADE |
| incident_history | previous_assignee | users.id | SET NULL | CASCADE |
| incident_history | new_assignee | users.id | SET NULL | CASCADE |
| audit_logs | user_id | users.id | SET NULL | CASCADE |

`login_throttle` has no relationship: its key is a digest, by design.

## What this means in practice

- **Deleting a user** keeps their incidents, alerts, history rows and audit events; the
  reference becomes `NULL`. History therefore survives account deletion, without the name.
- **Deleting an incident** removes its history (the only cascade in the schema).
- **Deleting a device or an event** leaves alerts and incidents in place, unlinked.
- The application itself never deletes anything: it only inserts and updates. Deletions happen
  through SQL or through test cleanup.

## Cardinalities

```text
device 1 ── n events
device 1 ── n alerts
device 1 ── n incidents
event  1 ── n alerts        (an alert may cite one event)
alert  1 ── n incidents     (an incident may cite one alert)
incident 1 ── n history rows
user   1 ── n incidents/alerts (as assignee)
user   1 ── n audit_logs
```

No many-to-many relationship exists, and no join table.
