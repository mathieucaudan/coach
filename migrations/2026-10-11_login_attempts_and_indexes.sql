-- Reference only: the in-app Migrations page applies the same changes idempotently.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email (email, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sessions ADD INDEX idx_sessions_athlete_date (athlete_id, date);
ALTER TABLE sessions ADD INDEX idx_sessions_date (date);
ALTER TABLE sessions ADD INDEX idx_sessions_attachment (attachment_url(191));
ALTER TABLE comments ADD INDEX idx_comments_session (session_id, created_at);
ALTER TABLE athletes ADD INDEX idx_athletes_user (user_id);

DELETE FROM auth_tokens WHERE expires_at < NOW();
