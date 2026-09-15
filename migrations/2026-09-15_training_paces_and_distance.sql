ALTER TABLE sessions
    ADD COLUMN planned_distance_km DECIMAL(6,2) NULL AFTER duration_min,
    ADD COLUMN actual_distance_km DECIMAL(6,2) NULL AFTER actual_duration_min,
    ADD COLUMN target_pace_code VARCHAR(40) NULL AFTER vma_percent;

ALTER TABLE session_debriefs
    ADD COLUMN actual_distance_km DECIMAL(6,2) NULL AFTER lactates;

ALTER TABLE daily_debriefs
    ADD COLUMN actual_distance_km DECIMAL(6,2) NULL AFTER lactates;

CREATE TABLE IF NOT EXISTS athlete_paces (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    athlete_id INT NOT NULL,
    code VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    percent_vma DECIMAL(5,2) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_athlete_paces_code (athlete_id, code),
    CONSTRAINT fk_athlete_paces_athlete
        FOREIGN KEY (athlete_id) REFERENCES athletes(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
