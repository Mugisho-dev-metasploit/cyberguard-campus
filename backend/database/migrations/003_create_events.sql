CREATE TABLE events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid CHAR(36) NOT NULL,

    device_id BIGINT UNSIGNED NULL,

    source VARCHAR(50) NOT NULL,
    event_type VARCHAR(100) NOT NULL,

    severity TINYINT UNSIGNED NOT NULL DEFAULT 1,

    event_timestamp DATETIME(6) NOT NULL,

    src_ip VARCHAR(45) NULL,
    src_port SMALLINT UNSIGNED NULL,

    dst_ip VARCHAR(45) NULL,
    dst_port SMALLINT UNSIGNED NULL,

    protocol VARCHAR(20) NULL,

    signature VARCHAR(500) NULL,
    category VARCHAR(100) NULL,

    raw_data JSON NOT NULL,
    normalized_data JSON NULL,

    processed_at DATETIME(6) NULL,

    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),

    UNIQUE KEY uq_events_uuid (event_uuid),

    CONSTRAINT fk_events_device
        FOREIGN KEY (device_id)
        REFERENCES devices(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_events_device_timestamp (device_id, event_timestamp),
    INDEX idx_events_timestamp (event_timestamp),
    INDEX idx_events_source_timestamp (source, event_timestamp),
    INDEX idx_events_src_ip_timestamp (src_ip, event_timestamp),
    INDEX idx_events_dst_ip_timestamp (dst_ip, event_timestamp),
    INDEX idx_events_type_timestamp (event_type, event_timestamp),
    INDEX idx_events_severity_timestamp (severity, event_timestamp),

    CONSTRAINT chk_events_severity
        CHECK (severity BETWEEN 1 AND 4),

    CONSTRAINT chk_events_src_port
        CHECK (src_port IS NULL OR src_port BETWEEN 1 AND 65535),

    CONSTRAINT chk_events_dst_port
        CHECK (dst_port IS NULL OR dst_port BETWEEN 1 AND 65535)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
