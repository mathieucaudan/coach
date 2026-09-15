CREATE TABLE IF NOT EXISTS coach_session_field_settings (
    coach_id INT NOT NULL,
    field_key VARCHAR(80) NOT NULL,
    visible TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (coach_id, field_key),
    CONSTRAINT fk_coach_session_field_settings_coach
        FOREIGN KEY (coach_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
