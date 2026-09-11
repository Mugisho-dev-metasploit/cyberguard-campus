CREATE TABLE alerts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_uuid CHAR(36) NOT NULL,

    event_id BIGINT UNSIGNED NULL,
    device_id BIGINT UNSIGNED NULL,

    source VARCHAR(50) NOT NULL,
    alert_type VARCHAR(100) NOT NULL,

    severity TINYINT UNSIGNED NOT NULL DEFAULT 1,

    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    signature VARCHAR(500) NULL,
    category VARCHAR(100) NULL,

    status ENUM(
        'new',
        'acknowledged',
        'resolved',
        'false_positive'
    ) NOT NULL DEFAULT 'new',

    detected_at DATETIME(6) NOT NULL,

    acknowledged_at DATETIME(6) NULL,
    resolved_at DATETIME(6) NULL,

    assigned_to BIGINT UNSIGNED NULL,

    metadata JSON NULL,

    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),

    UNIQUE KEY uq_alerts_uuid (alert_uuid),

    CONSTRAINT fk_alerts_event
        FOREIGN KEY (event_id)
        REFERENCES events(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_alerts_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_alerts_assigned_to
        FOREIGN KEY (assigned_to)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_alerts_event (event_id),
    INDEX idx_alerts_device_detected (device_id, detected_at),
    INDEX idx_alerts_status_severity (status, severity),
    INDEX idx_alerts_detected_at (detected_at),
    INDEX idx_alerts_source_detected (source, detected_at),
    INDEX idx_alerts_type_detected (alert_type, detected_at),
    INDEX idx_alerts_assigned_to (assigned_to),

    CONSTRAINT chk_alerts_severity
        CHECK (severity BETWEEN 1 AND 4)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
