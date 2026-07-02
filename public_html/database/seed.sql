-- Mot de passe : password123
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM comments;
DELETE FROM sessions;
DELETE FROM athletes;
DELETE FROM users;
ALTER TABLE comments AUTO_INCREMENT = 1;
ALTER TABLE sessions AUTO_INCREMENT = 1;
ALTER TABLE athletes AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO users (id, name, email, password_hash, role) VALUES
(1, 'Coach Demo', 'coach@example.com', '$2b$10$5AoLDrRSQ2GnGIL902UsKeFF9q4n3JpvTvoD6QBggYowI90k2vOkW', 'coach'),
(2, 'Mathieu Caudan', 'athlete@example.com', '$2b$10$5AoLDrRSQ2GnGIL902UsKeFF9q4n3JpvTvoD6QBggYowI90k2vOkW', 'athlete');

INSERT INTO athletes (id, coach_id, user_id, first_name, last_name, email) VALUES
(1, 1, 2, 'Mathieu', 'Caudan', 'athlete@example.com');

INSERT INTO sessions (athlete_id, coach_id, date, title, type, description, objective, warmup, main_workout, cooldown, coach_notes, external_link) VALUES
(1, 1, CURDATE(), 'Footing 45 min', 'footing', 'Footing en endurance fondamentale.', 'Relancer proprement sans fatigue.', '10 min très facile.', '35 min en aisance respiratoire.', '5 min marche + mobilité.', 'Rester bas en intensité.', 'https://example.com'),
(1, 1, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'VMA courte', 'fractionné', 'Séance de fractionné court.', 'Stimuler la vitesse aérobie.', '20 min footing + gammes.', '10 x 300 m vite / 100 m lent.', '10 min footing lent.', 'Ne pas partir trop vite.', NULL),
(1, 1, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Sortie longue', 'sortie longue', 'Sortie longue progressive.', 'Construire l’endurance.', '15 min faciles.', '1h10 en endurance, finir légèrement plus soutenu.', 'Étirements légers.', 'Hydratation obligatoire.', NULL),
(1, 1, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 'Repos', 'repos', 'Repos complet.', 'Assimilation.', '', '', '', 'Aucune intensité.', NULL);

INSERT INTO comments (session_id, user_id, content) VALUES
(1, 2, 'Séance bien passée, jambes lourdes sur la fin.'),
(1, 1, 'Bien noté, on garde une séance légère demain.');
