# Endpoints

Base URL in the laboratory: `http://<host>/cyberguard-campus/backend/public/index.php`.
All responses carry `Content-Type: application/json; charset=utf-8`.

---

## GET /health

| | |
|---|---|
| Authentication | None |
| Response | `200 {"success":true,"application":"CYBERGUARD CAMPUS","status":"healthy"}` |
| Errors | 405 with `Allow: GET` for any other method |
| Side effects | None (but the database connection is opened during bootstrap) |
| Security | Reveals only the product name |

---

## POST /login

| | |
|---|---|
| Authentication | None |
| Headers | `Content-Type: application/json` **required**; `Origin` checked when present |
| Body | `{"identifier": string, "password": string}` |
| Validation | Both non-empty strings; identifier ≤ 254 characters after trim; password ≤ 1024 bytes |
| Success | `200 {"success":true,"message":"Authentication successful.","user":{uuid,username,email,first_name,last_name,role}}` + `Set-Cookie` |
| Errors | 415 `Unsupported content type.`; 403 `Cross-origin sign-in refused.`; 401 `Invalid credentials.`; 429 `Too many sign-in attempts. Try again later.` + `Retry-After`; 500 `Unable to sign in.` |
| Response headers | `Cache-Control: no-store`, `Pragma: no-cache` |
| Side effects | Session created and regenerated; throttle buckets updated; `audit_logs` event; `last_login_at` updated; password hash upgraded when weaker than policy |
| Security | Same 401 for unknown, inactive, locked, deleted accounts and wrong passwords; one bcrypt verification in every case |

---

## POST /logout

| | |
|---|---|
| Authentication | Works with or without a session |
| Body | Ignored; no `Content-Type` required |
| Success | `200 {"success":true,"message":"Logged out successfully."}` |
| Errors | 405 for other methods |
| Response headers | `Cache-Control: no-store`, `Pragma: no-cache`; `Set-Cookie` expiring the session cookie when one was presented |
| Side effects | Session destroyed server-side; `auth.logout` recorded when a valid session was presented |
| Security | Identical answer whether the session existed, expired or never was |

---

## GET /api/incidents

| | |
|---|---|
| Authentication | Required |
| Roles | viewer, analyst, admin |
| Parameters | None |
| Success | `200 {"success":true,"message":"Incidents retrieved successfully.","data":[…]}` |
| Item fields | `id`, `incident_uuid`, `incident_number`, `title`, `description`, `severity`, `status`, `priority`, `assigned_to`, `detected_at`, `acknowledged_at`, `contained_at`, `resolved_at`, `closed_at`, `created_at`, `updated_at` |
| Ordering | `detected_at DESC, id DESC` |
| Errors | 401, 403, 500 `Unable to retrieve incidents.` (with `data: []`) |

---

## GET /api/incidents/{id}

| | |
|---|---|
| Authentication | Required |
| Roles | viewer, analyst, admin |
| Path parameter | `id`: positive integer; anything else → 400 `Invalid incident identifier.` |
| Success | `200 … "data": {"incident": {…}, "history": […]}` |
| History fields | `id`, `user_id`, `action`, `previous_status`, `new_status`, `previous_assignee`, `new_assignee`, `comment`, `created_at`, plus the actor's public name fields, oldest first |
| Errors | 400, 401, 403, 404 `Incident not found.`, 500 |

---

## PATCH /api/incidents/{id}

| | |
|---|---|
| Authentication | Required |
| Roles | analyst, admin (`closed` requires admin) |
| Body | Any subset of `title`, `description`, `severity`, `status`, `priority`, `assigned_to`, `resolution` |
| Validation | Unknown field → 422 naming it; `severity` 1–4; `priority` in `low,medium,high,critical`; `assigned_to` null or existing user; status transitions one step at a time |
| Success | `200 … "data": {updated incident}` |
| Errors | 400 `Invalid incident identifier.` / `Invalid JSON payload.`; 401; 403 `Insufficient permissions.`; 404; 422 (validation or workflow); 500 `Unable to update incident.` |
| Side effects | Row locked, incident updated, lifecycle timestamp stamped, `incident_history` row written, all in one transaction |
| Security | Actor id and role come from the session only; a `role` field in the body is rejected as unknown |

---

## GET /api/events

| | |
|---|---|
| Authentication | Required — roles: viewer, analyst, admin |
| Success | `data`: `id`, `event_uuid`, `device_id`, `source`, `event_type`, `severity`, `event_timestamp`, `src_ip`, `src_port`, `dst_ip`, `dst_port`, `protocol`, `signature`, `category`, `processed_at`, `created_at` |
| Ordering | `event_timestamp DESC, id DESC` |
| Notes | `raw_data` and `normalized_data` are **not** exposed |
| Errors | 401, 403, 500 `Unable to retrieve events.` |

---

## GET /api/alerts

| | |
|---|---|
| Authentication | Required — roles: viewer, analyst, admin |
| Success | `data`: `id`, `alert_uuid`, `event_id`, `device_id`, `source`, `alert_type`, `severity`, `title`, `description`, `signature`, `category`, `status`, `detected_at`, `acknowledged_at`, `resolved_at`, `assigned_to`, `created_at`, `updated_at` |
| Ordering | `detected_at DESC, id DESC` |
| Errors | 401, 403, 500 `Unable to retrieve alerts.` |

---

## GET /api/devices

| | |
|---|---|
| Authentication | Required — roles: viewer, analyst, admin |
| Success | `data`: `id`, `uuid`, `hostname`, `ip_address`, `mac_address`, `device_type`, `vendor`, `operating_system`, `environment`, `status`, `last_seen_at`, `created_at`, `updated_at` |
| Ordering | `last_seen_at DESC, id DESC` |
| Errors | 401, 403, 500 `Unable to retrieve devices.` |

---

## GET /api/metrics

| | |
|---|---|
| Authentication | Required — roles: viewer, analyst, admin |
| Success | `data`: `total_events`, `total_alerts`, `critical_alerts` (severity 4), `open_incidents` (status not `resolved`/`closed`), `devices` — all integers |
| Errors | 401, 403, 500 `Unable to retrieve metrics.` |

---

## Router-wide behaviour

| Situation | Answer |
|---|---|
| Known path, other method | `405 {"success":false,"message":"Method not allowed."}` + `Allow` |
| Unknown path | `404 {"success":false,"message":"Route not found."}` |
| `HEAD` on a `GET` route | 405 (`HEAD` is not mapped to `GET`) |
