CREATE TABLE devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,

    hostname VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    mac_address VARCHAR(17) NULL,

    device_type ENUM(
        'router',
        'switch',
        'firewall',
        'sensor',
        'server',
        'workstation',
        'other'
    ) NOT NULL DEFAULT 'other',

    vendor VARCHAR(100) NULL,
    operating_system VARCHAR(100) NULL,

    environment ENUM(
        'production',
        'laboratory',
        'development'
    ) NOT NULL DEFAULT 'laboratory',

    status ENUM(
        'online',
        'offline',
        'degraded',
        'unknown'
    ) NOT NULL DEFAULT 'unknown',

    last_seen_at DATETIME(6) NULL,

    metadata JSON NULL,

    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),

    UNIQUE KEY uq_devices_uuid (uuid),

    INDEX idx_devices_hostname (hostname),
    INDEX idx_devices_ip_address (ip_address),
    INDEX idx_devices_type_status (device_type, status),
    INDEX idx_devices_environment (environment),
    INDEX idx_devices_last_seen (last_seen_at),

    CONSTRAINT chk_devices_mac
        CHECK (
            mac_address IS NULL
            OR mac_address REGEXP '^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$'
        )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
