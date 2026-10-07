-- vélo / natation: legacy ENUM type column rejected new session types.
ALTER TABLE sessions MODIFY type VARCHAR(64) NULL DEFAULT 'footing';
