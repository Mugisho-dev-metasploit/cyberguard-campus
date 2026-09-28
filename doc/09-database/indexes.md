# Indexes

Indexes as declared in the migrations.

| Table | Index | Columns | Purpose |
|---|---|---|---|
| users | PRIMARY / unique | id / uuid, username, email | identity lookups at sign-in |
| users | idx_users_role_status | role, status | filtering by role and state |
| users | idx_users_created_at, idx_users_deleted_at | created_at / deleted_at | housekeeping, soft-delete filters |
| devices | unique | uuid | |
| devices | hostname, ip_address, device_type, environment, last_seen_at | single-column | inventory lookups and the list ordering |
| events | unique | event_uuid | |
| events | device_id, source, event_type, severity, event_timestamp, src_ip, dst_ip | single-column | the list ordering (`event_timestamp DESC`) and future filters |
| alerts | unique | alert_uuid | |
| alerts | event_id, device_id, source, alert_type, status, detected_at, assigned_to | single-column | list ordering and triage filters |
| incidents | unique | incident_uuid, incident_number | |
| incidents | alert_id, device_id, severity, status, assigned_to, detected_at, created_at | single-column | list ordering and dashboards |
| incident_history | incident_id, user_id, action, created_at, assignee columns | single-column | detail view, audit |
| audit_logs | user_id+created_at, action+created_at, resource, ip_address+created_at, success+created_at, created_at | composite and single | the throttle de-duplication query and forensic lookups |
| login_throttle | PRIMARY (throttle_key), idx_login_throttle_last_attempt | | bucket lookup and the idle purge |

## Observations

- The list endpoints order by the indexed recency column, so they can use an index, but they
  have no `LIMIT`: the whole table is read and serialised.
- `audit_logs` carries six indexes on a write-mostly table; that is generous but deliberate, to
  keep forensic queries cheap. Write cost grows with them.
- No covering index was designed for the metrics query; it runs five `COUNT(*)` sub-queries.
- No full-text index anywhere.
