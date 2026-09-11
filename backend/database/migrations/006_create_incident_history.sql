CREATE TABLE incident_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    incident_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,

    previous_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,

    previous_assignee BIGINT UNSIGNED NULL,
    new_assignee BIGINT UNSIGNED NULL,

    comment TEXT NULL,

    metadata JSON NULL,

    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (id),

    CONSTRAINT fk_incident_history_incident
        FOREIGN KEY (incident_id)
        REFERENCES incidents(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_incident_history_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_incident_history_previous_assignee
        FOREIGN KEY (previous_assignee)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_incident_history_new_assignee
        FOREIGN KEY (new_assignee)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_incident_history_incident_created (
        incident_id,
        created_at
    ),

    INDEX idx_incident_history_user_created (
        user_id,
        created_at
    ),

    INDEX idx_incident_history_action_created (
        action,
        created_at
    ),

    INDEX idx_incident_history_created_at (
        created_at
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
