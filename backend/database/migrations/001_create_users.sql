CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,

    role ENUM('admin', 'analyst', 'viewer') NOT NULL DEFAULT 'viewer',
    status ENUM('active', 'inactive', 'locked') NOT NULL DEFAULT 'active',

    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),

    INDEX idx_users_role_status (role, status),
    INDEX idx_users_created_at (created_at),
    INDEX idx_users_deleted_at (deleted_at),

    CONSTRAINT chk_users_username
        CHECK (CHAR_LENGTH(username) >= 3),

    CONSTRAINT chk_users_email
        CHECK (email LIKE '%@%'),

    CONSTRAINT chk_users_password_hash
        CHECK (CHAR_LENGTH(password_hash) >= 60)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
