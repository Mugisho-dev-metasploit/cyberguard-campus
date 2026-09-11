CREATE TABLE incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_uuid CHAR(36) NOT NULL,

    alert_id BIGINT UNSIGNED NULL,
    device_id BIGINT UNSIGNED NULL,

    incident_number VARCHAR(30) NOT NULL,

    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    severity TINYINT UNSIGNED NOT NULL DEFAULT 1,

    status ENUM(
        'open',
        'acknowledged',
        'investigating',
        'contained',
        'resolved',
        'closed'
    ) NOT NULL DEFAULT 'open',

    priority ENUM(
        'low',
        'medium',
        'high',
        'critical'
    ) NOT NULL DEFAULT 'medium',

    assigned_to BIGINT UNSIGNED NULL,

    detected_at DATETIME(6) NOT NULL,
    acknowledged_at DATETIME(6) NULL,
    contained_at DATETIME(6) NULL,
    resolved_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL,

    resolution TEXT NULL,

    metadata JSON NULL,

    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),

    UNIQUE KEY uq_incidents_uuid (incident_uuid),
    UNIQUE KEY uq_incidents_number (incident_number),

    CONSTRAINT fk_incidents_alert
        FOREIGN KEY (alert_id)
        REFERENCES alerts(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_incidents_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_incidents_assigned_to
        FOREIGN KEY (assigned_to)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_incidents_alert (alert_id),
    INDEX idx_incidents_device_detected (device_id, detected_at),
    INDEX idx_incidents_status_priority (status, priority),
    INDEX idx_incidents_severity_status (severity, status),
    INDEX idx_incidents_assigned_to (assigned_to),
    INDEX idx_incidents_detected_at (detected_at),
    INDEX idx_incidents_created_at (created_at),

    CONSTRAINT chk_incidents_severity
        CHECK (severity BETWEEN 1 AND 4)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
