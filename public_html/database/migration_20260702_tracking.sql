ALTER TABLE athletes
  ADD COLUMN sport VARCHAR(100) NOT NULL DEFAULT 'Course' AFTER email,
  ADD COLUMN level VARCHAR(60) NOT NULL DEFAULT 'Intermediaire' AFTER sport,
  ADD COLUMN goal VARCHAR(255) DEFAULT NULL AFTER level,
  ADD COLUMN vma DECIMAL(4,1) NOT NULL DEFAULT 15.0 AFTER goal,
  ADD COLUMN notes TEXT AFTER vma;

ALTER TABLE sessions
  MODIFY COLUMN type VARCHAR(80) NOT NULL,
  ADD COLUMN status ENUM('planned','done','missed','cancelled') NOT NULL DEFAULT 'planned' AFTER type,
  ADD COLUMN intensity ENUM('low','moderate','high','max') NOT NULL DEFAULT 'moderate' AFTER status,
  ADD COLUMN duration_min INT DEFAULT NULL AFTER intensity,
  ADD COLUMN vma_percent DECIMAL(5,1) DEFAULT NULL AFTER duration_min,
  ADD COLUMN actual_duration_min INT DEFAULT NULL AFTER coach_notes,
  ADD COLUMN feeling TINYINT DEFAULT NULL AFTER actual_duration_min,
  ADD COLUMN pain TINYINT DEFAULT NULL AFTER feeling,
  ADD COLUMN athlete_feedback TEXT AFTER pain;
