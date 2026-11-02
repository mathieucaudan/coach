-- Reference only: the in-app Migrations page applies the same changes idempotently.
-- If run by hand, skip any ADD COLUMN that fails with "Duplicate column name".
ALTER TABLE athletes ADD COLUMN wellness_tracking TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE session_debriefs ADD COLUMN duration_min INT NULL;
ALTER TABLE session_debriefs ADD COLUMN rpe TINYINT UNSIGNED NULL;
ALTER TABLE session_debriefs ADD COLUMN session_comment TEXT NULL;

ALTER TABLE daily_debriefs ADD COLUMN duration_min INT NULL;
ALTER TABLE daily_debriefs ADD COLUMN rpe TINYINT UNSIGNED NULL;
ALTER TABLE daily_debriefs ADD COLUMN session_comment TEXT NULL;

CREATE TABLE IF NOT EXISTS daily_wellness (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    athlete_id INT NOT NULL,
    date DATE NOT NULL,
    sleep TINYINT UNSIGNED NULL,
    energy TINYINT UNSIGNED NULL,
    soreness TINYINT UNSIGNED NULL,
    stress TINYINT UNSIGNED NULL,
    motivation TINYINT UNSIGNED NULL,
    menstrual_cycle TINYINT(1) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_wellness_athlete_date (athlete_id, date),
    CONSTRAINT fk_daily_wellness_athlete
        FOREIGN KEY (athlete_id) REFERENCES athletes(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
