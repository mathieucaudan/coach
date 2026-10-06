-- Prefer the in-app Migrations page: it only adds the columns that are missing.
-- If run by hand, skip any ADD COLUMN that fails with "Duplicate column name".
ALTER TABLE sessions ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'planned';
ALTER TABLE sessions ADD COLUMN intensity VARCHAR(32) NOT NULL DEFAULT 'moderate';
ALTER TABLE sessions ADD COLUMN duration_min INT NULL;
ALTER TABLE sessions ADD COLUMN vma_percent DECIMAL(5,2) NULL;
ALTER TABLE sessions ADD COLUMN planned_distance_km DECIMAL(6,2) NULL;
ALTER TABLE sessions ADD COLUMN actual_duration_min INT NULL;
ALTER TABLE sessions ADD COLUMN actual_distance_km DECIMAL(6,2) NULL;
ALTER TABLE sessions ADD COLUMN target_pace_code VARCHAR(40) NULL;
ALTER TABLE sessions ADD COLUMN feeling TINYINT NULL;
ALTER TABLE sessions ADD COLUMN pain TINYINT NULL;
ALTER TABLE sessions ADD COLUMN athlete_feedback TEXT NULL;

ALTER TABLE athletes ADD COLUMN sport VARCHAR(80) NOT NULL DEFAULT 'Course';
ALTER TABLE athletes ADD COLUMN level VARCHAR(80) NOT NULL DEFAULT 'Intermediaire';
ALTER TABLE athletes ADD COLUMN goal TEXT NULL;
ALTER TABLE athletes ADD COLUMN vma DECIMAL(4,1) NOT NULL DEFAULT 15;
ALTER TABLE athletes ADD COLUMN notes TEXT NULL;

ALTER TABLE session_debriefs ADD COLUMN actual_distance_km DECIMAL(6,2) NULL;

ALTER TABLE daily_debriefs ADD COLUMN actual_distance_km DECIMAL(6,2) NULL;

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
