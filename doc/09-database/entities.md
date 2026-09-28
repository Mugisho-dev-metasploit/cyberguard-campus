# Entities

Every column as it exists in the database. `NN` = NOT NULL.

## users

| Column | Type | NN | Notes |
|---|---|:--:|---|
| id | bigint unsigned | ✓ | PK |
| uuid | char(36) | ✓ | unique |
| username | varchar(50) | ✓ | unique; check: length ≥ 3 |
| email | varchar(254) | ✓ | unique |
| password_hash | varchar(255) | ✓ | bcrypt; never leaves the repository layer |
| first_name / last_name | varchar(100) | ✓ | |
| role | enum(`admin`,`analyst`,`viewer`) | ✓ | default `viewer` |
| status | enum(`active`,`inactive`,`locked`) | ✓ | default `active` |
| last_login_at | datetime(6) | | set on successful sign-in |
| created_at / updated_at | datetime(6) | ✓ | `updated_at` auto-updates |
| deleted_at | datetime(6) | | soft delete |

## devices

| Column | Type | NN | Notes |
|---|---|:--:|---|
| id, uuid | bigint / char(36) | ✓ | PK, unique |
| hostname | varchar(255) | ✓ | indexed |
| ip_address | varchar(45) | | IPv4/IPv6 |
| mac_address | varchar(17) | | |
| device_type | enum(`router`,`switch`,`firewall`,`sensor`,`server`,`workstation`,`other`) | ✓ | |
| vendor, operating_system | varchar(100) | | |
| environment | enum(`production`,`laboratory`,`development`) | ✓ | |
| status | enum(`online`,`offline`,`degraded`,`unknown`) | ✓ | recorded state, not measured by the app |
| last_seen_at | datetime(6) | | |
| metadata | longtext (JSON) | | |

## events

| Column | Type | NN | Notes |
|---|---|:--:|---|
| id, event_uuid | bigint / char(36) | ✓ | PK, unique |
| device_id | bigint unsigned | | FK → devices |
| source, event_type | varchar(50) / varchar(100) | ✓ | e.g. sensor name, event class |
| severity | tinyint unsigned | ✓ | 1–4 (check constraint) |
| event_timestamp | datetime(6) | ✓ | when the source saw it |
| src_ip, dst_ip | varchar(45) | | |
| src_port, dst_port | smallint unsigned | | |
| protocol | varchar(20) | | |
| signature, category | varchar(500) / varchar(100) | | |
| raw_data | longtext (JSON) | ✓ | not exposed by the API |
| normalized_data | longtext (JSON) | | not exposed by the API |
| processed_at | datetime(6) | | never written by the application today |

## alerts

| Column | Type | NN | Notes |
|---|---|:--:|---|
| id, alert_uuid | bigint / char(36) | ✓ | PK, unique |
| event_id, device_id, assigned_to | bigint unsigned | | FK → events, devices, users |
| source, alert_type | varchar(50) / varchar(100) | ✓ | |
| severity | tinyint unsigned | ✓ | 1–4 |
| title | varchar(255) | ✓ | |
| description, signature, category | text / varchar(500) / varchar(100) | | |
| status | enum(`new`,`acknowledged`,`resolved`,`false_positive`) | ✓ | no API writes it |
| detected_at | datetime(6) | ✓ | |
| acknowledged_at, resolved_at | datetime(6) | | never written by the application today |
| metadata | longtext (JSON) | | not exposed by the API |

## incidents

| Column | Type | NN | Notes |
|---|---|:--:|---|
| id, incident_uuid | bigint / char(36) | ✓ | PK, unique |
| incident_number | varchar(30) | ✓ | unique, human reference |
| alert_id, device_id, assigned_to | bigint unsigned | | FK |
| title | varchar(255) | ✓ | |
| description, resolution | text | | |
| severity | tinyint unsigned | ✓ | 1–4 |
| status | enum(`open`,`acknowledged`,`investigating`,`contained`,`resolved`,`closed`) | ✓ | state machine |
| priority | enum(`low`,`medium`,`high`,`critical`) | ✓ | |
| detected_at | datetime(6) | ✓ | |
| acknowledged_at, contained_at, resolved_at, closed_at | datetime(6) | | stamped on transition |
| metadata | longtext (JSON) | | not exposed by the API |

## incident_history

One row per accepted change: `incident_id`, `user_id` (actor), `action`, `previous_status`,
`new_status`, `previous_assignee`, `new_assignee`, `comment`, `metadata`, `created_at`.
Written only by `IncidentRepository`, inside the update transaction.

## audit_logs

`user_id` (nullable), `action`, `resource_type`, `resource_id`, `ip_address` (≤ 45),
`user_agent` (≤ 1000, application caps at 255), `success`, `details` (JSON), `created_at`.
Actions written today: `auth.login.success`, `auth.login.failure`, `auth.login.throttled`,
`auth.logout`, `auth.session.revoked`. `resource_type` and `resource_id` are unused so far.

## login_throttle

`throttle_key` (SHA-256 digest, PK), `scope` (`account`, `source_account`), `attempts`,
`last_attempt_at`, `blocked_until`. Holds no identifier and no address in clear.
