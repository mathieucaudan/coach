CREATE TABLE IF NOT EXISTS bank_connections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'gocardless',
  institution_id VARCHAR(190) NOT NULL,
  institution_name VARCHAR(255) NOT NULL,
  requisition_id VARCHAR(190) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  callback_state_hash CHAR(64) NULL,
  callback_state_expires_at DATETIME NULL,
  consent_expires_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_bank_connections_requisition (requisition_id),
  KEY idx_bank_connections_owner (user_id, enabled),
  CONSTRAINT fk_bank_connections_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_provider_tokens (
  provider VARCHAR(32) NOT NULL, access_token TEXT NULL, refresh_token TEXT NULL,
  access_expires_at DATETIME NULL, refresh_expires_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, bank_connection_id INT UNSIGNED NOT NULL,
  provider_account_id VARCHAR(190) NOT NULL, iban VARCHAR(64) NULL, name VARCHAR(255) NULL,
  currency CHAR(3) NULL, current_balance DECIMAL(18,2) NULL, available_balance DECIMAL(18,2) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1, last_sync_at DATETIME NULL, last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_bank_accounts_provider (provider_account_id), KEY idx_bank_accounts_connection (bank_connection_id, enabled),
  CONSTRAINT fk_bank_accounts_connection FOREIGN KEY (bank_connection_id) REFERENCES bank_connections(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, bank_account_id INT UNSIGNED NOT NULL, provider VARCHAR(32) NOT NULL DEFAULT 'gocardless',
  provider_transaction_id VARCHAR(255) NULL, deduplication_key CHAR(64) NOT NULL, booking_date DATE NULL, value_date DATE NULL,
  amount DECIMAL(18,2) NOT NULL, currency CHAR(3) NOT NULL, raw_label TEXT NULL, clean_label VARCHAR(500) NULL,
  creditor_name VARCHAR(255) NULL, debtor_name VARCHAR(255) NULL, status VARCHAR(16) NOT NULL,
  raw_data JSON NULL, accounting_entry_id BIGINT UNSIGNED NULL, category_id BIGINT UNSIGNED NULL, attachment_id BIGINT UNSIGNED NULL,
  reconciliation_status VARCHAR(32) NOT NULL DEFAULT 'unclassified', reconciliation_score DECIMAL(5,2) NULL,
  suggested_label VARCHAR(255) NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_bank_transactions_dedup (bank_account_id, deduplication_key),
  KEY idx_bank_transactions_provider_id (bank_account_id, provider_transaction_id),
  KEY idx_bank_transactions_filters (bank_account_id, booking_date, status, reconciliation_status),
  CONSTRAINT fk_bank_transactions_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_sync_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, bank_connection_id INT UNSIGNED NULL, bank_account_id INT UNSIGNED NULL,
  level VARCHAR(16) NOT NULL, event VARCHAR(80) NOT NULL, message VARCHAR(500) NOT NULL, context JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_bank_sync_logs_created (created_at),
  CONSTRAINT fk_bank_sync_logs_connection FOREIGN KEY (bank_connection_id) REFERENCES bank_connections(id) ON DELETE SET NULL,
  CONSTRAINT fk_bank_sync_logs_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, name VARCHAR(190) NOT NULL,
  match_type VARCHAR(32) NOT NULL DEFAULT 'label_contains', match_value VARCHAR(255) NOT NULL,
  suggested_category_id BIGINT UNSIGNED NULL, suggested_label VARCHAR(255) NULL, enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_bank_rules_owner (user_id, enabled),
  CONSTRAINT fk_bank_rules_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
