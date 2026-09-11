CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NULL,
    resource_id BIGINT UNSIGNED NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(1000) NULL,

    success BOOLEAN NOT NULL DEFAULT TRUE,

    details JSON NULL,

    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),

    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_audit_logs_user_created (
        user_id,
        created_at
    ),

    INDEX idx_audit_logs_action_created (
        action,
        created_at
    ),

    INDEX idx_audit_logs_resource (
        resource_type,
        resource_id
    ),

    INDEX idx_audit_logs_ip_created (
        ip_address,
        created_at
    ),

    INDEX idx_audit_logs_success_created (
        success,
        created_at
    ),

    INDEX idx_audit_logs_created_at (
        created_at
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
