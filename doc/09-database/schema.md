# Database schema

Engine: **MariaDB 11.8.6**, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, database `cyberguard`.
Eight tables, created by the eight files in `backend/database/migrations`.

```mermaid
erDiagram
    USERS ||--o{ INCIDENTS : "assigned_to"
    USERS ||--o{ ALERTS : "assigned_to"
    USERS ||--o{ INCIDENT_HISTORY : "actor / assignees"
    USERS ||--o{ AUDIT_LOGS : "user_id"
    DEVICES ||--o{ EVENTS : "device_id"
    DEVICES ||--o{ ALERTS : "device_id"
    DEVICES ||--o{ INCIDENTS : "device_id"
    EVENTS ||--o{ ALERTS : "event_id"
    ALERTS ||--o{ INCIDENTS : "alert_id"
    INCIDENTS ||--o{ INCIDENT_HISTORY : "incident_id"

    USERS {
        bigint id PK
        char uuid UK
        varchar username UK
        varchar email UK
        varchar password_hash
        varchar first_name
        varchar last_name
        enum role
        enum status
        datetime last_login_at
        datetime created_at
        datetime updated_at
        datetime deleted_at
    }
    DEVICES {
        bigint id PK
        char uuid UK
        varchar hostname
        varchar ip_address
        varchar mac_address
        enum device_type
        varchar vendor
        varchar operating_system
        enum environment
        enum status
        datetime last_seen_at
        longtext metadata
    }
    EVENTS {
        bigint id PK
        char event_uuid UK
        bigint device_id FK
        varchar source
        varchar event_type
        tinyint severity
        datetime event_timestamp
        varchar src_ip
        smallint src_port
        varchar dst_ip
        smallint dst_port
        varchar protocol
        varchar signature
        varchar category
        longtext raw_data
        longtext normalized_data
        datetime processed_at
    }
    ALERTS {
        bigint id PK
        char alert_uuid UK
        bigint event_id FK
        bigint device_id FK
        varchar source
        varchar alert_type
        tinyint severity
        varchar title
        text description
        varchar signature
        varchar category
        enum status
        datetime detected_at
        datetime acknowledged_at
        datetime resolved_at
        bigint assigned_to FK
        longtext metadata
    }
    INCIDENTS {
        bigint id PK
        char incident_uuid UK
        bigint alert_id FK
        bigint device_id FK
        varchar incident_number UK
        varchar title
        text description
        tinyint severity
        enum status
        enum priority
        bigint assigned_to FK
        datetime detected_at
        datetime acknowledged_at
        datetime contained_at
        datetime resolved_at
        datetime closed_at
        text resolution
        longtext metadata
    }
    INCIDENT_HISTORY {
        bigint id PK
        bigint incident_id FK
        bigint user_id FK
        varchar action
        varchar previous_status
        varchar new_status
        bigint previous_assignee FK
        bigint new_assignee FK
        text comment
        longtext metadata
        datetime created_at
    }
    AUDIT_LOGS {
        bigint id PK
        bigint user_id FK
        varchar action
        varchar resource_type
        bigint resource_id
        varchar ip_address
        varchar user_agent
        boolean success
        longtext details
        datetime created_at
    }
    LOGIN_THROTTLE {
        char throttle_key PK
        enum scope
        int attempts
        datetime last_attempt_at
        datetime blocked_until
    }
```

## Conventions

- Primary keys: `BIGINT UNSIGNED AUTO_INCREMENT`, except `login_throttle` (a `CHAR(64)` digest).
- Public identifiers: `CHAR(36)` UUIDs, unique, on users, devices, events, alerts, incidents.
- Timestamps: `DATETIME(6)` (microseconds), `created_at` defaulted, `updated_at` with
  `ON UPDATE CURRENT_TIMESTAMP(6)` where present.
- Soft delete: only `users.deleted_at`; every other table is hard-deleted.
- JSON payloads (`metadata`, `details`, `normalized_data`, `raw_data`) are stored as `LONGTEXT`
  (declared `JSON` in the migrations; MariaDB maps that to `LONGTEXT` with a check constraint).

Column-by-column detail: [entities.md](entities.md). Relationships and delete rules:
[relationships.md](relationships.md).
