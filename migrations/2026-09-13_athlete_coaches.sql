CREATE TABLE IF NOT EXISTS athlete_coaches (
    athlete_id INT NOT NULL,
    coach_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (athlete_id, coach_id),
    CONSTRAINT fk_athlete_coaches_athlete
        FOREIGN KEY (athlete_id) REFERENCES athletes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_athlete_coaches_coach
        FOREIGN KEY (coach_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO athlete_coaches (athlete_id, coach_id, created_at)
SELECT id, coach_id, NOW()
FROM athletes
WHERE coach_id IS NOT NULL;
