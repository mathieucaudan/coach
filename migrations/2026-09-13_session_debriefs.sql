CREATE TABLE IF NOT EXISTS session_debriefs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id INT NOT NULL,
    result TEXT NULL,
    difficulty TINYINT UNSIGNED NULL,
    sensations TEXT NULL,
    weather JSON NULL,
    temperature_c DECIMAL(4,1) NULL,
    lactates TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_debriefs_session (session_id),
    CONSTRAINT chk_session_debriefs_difficulty CHECK (difficulty IS NULL OR difficulty BETWEEN 1 AND 10),
    CONSTRAINT fk_session_debriefs_session
        FOREIGN KEY (session_id) REFERENCES sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
