CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('coach','athlete') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS athletes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  coach_id INT NOT NULL,
  user_id INT NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  sport VARCHAR(100) NOT NULL DEFAULT 'Course',
  level VARCHAR(60) NOT NULL DEFAULT 'Intermediaire',
  goal VARCHAR(255) DEFAULT NULL,
  vma DECIMAL(4,1) NOT NULL DEFAULT 15.0,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_athlete_coach FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_athlete_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  athlete_id INT NOT NULL,
  coach_id INT NOT NULL,
  date DATE NOT NULL,
  title VARCHAR(120) NOT NULL,
  type VARCHAR(80) NOT NULL,
  status ENUM('planned','done','missed','cancelled') NOT NULL DEFAULT 'planned',
  intensity ENUM('low','moderate','high','max') NOT NULL DEFAULT 'moderate',
  duration_min INT DEFAULT NULL,
  vma_percent DECIMAL(5,1) DEFAULT NULL,
  description TEXT,
  objective TEXT,
  warmup TEXT,
  main_workout TEXT,
  cooldown TEXT,
  coach_notes TEXT,
  actual_duration_min INT DEFAULT NULL,
  feeling TINYINT DEFAULT NULL,
  pain TINYINT DEFAULT NULL,
  athlete_feedback TEXT,
  attachment_url VARCHAR(500),
  external_link VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sessions_athlete_date (athlete_id, date),
  CONSTRAINT fk_session_athlete FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
  CONSTRAINT fk_session_coach FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  user_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comment_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
