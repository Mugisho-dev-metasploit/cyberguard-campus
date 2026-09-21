-- APP-07.4.1 — sign-in throttling (anti brute-force).
-- One row per throttling bucket: the sign-in identifier alone ('account'), or the identifier
-- from one client address ('source_account'). The key is a SHA-256 digest computed by MariaDB
-- from the identifier's collation weights (utf8mb4_unicode_ci, the collation users are matched
-- with), so case, accent or width variants of one identifier share one bucket. No identifier,
-- address or password is stored. Rows idle for 2 hours are purged by the application.
CREATE TABLE login_throttle (
    throttle_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope ENUM('account', 'source_account') NOT NULL,

    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME(6) NOT NULL,
    blocked_until DATETIME(6) NULL,

    PRIMARY KEY (throttle_key),

    INDEX idx_login_throttle_last_attempt (
        last_attempt_at
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
