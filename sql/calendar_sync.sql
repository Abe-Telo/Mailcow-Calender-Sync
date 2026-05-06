CREATE TABLE IF NOT EXISTS calendar_sync_accounts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner_mailbox VARCHAR(255) NOT NULL,
  provider ENUM('google','microsoft') NOT NULL,
  provider_account_email VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NULL,
  encrypted_access_token TEXT NULL,
  encrypted_refresh_token TEXT NULL,
  token_expires_at DATETIME NULL,
  scopes TEXT NULL,
  oauth_state VARCHAR(128) NULL,
  crypto_key_version VARCHAR(32) NULL,
  status ENUM('pending_oauth','active','revoked','error') NOT NULL DEFAULT 'pending_oauth',
  last_error_message VARCHAR(512) NULL,
  connected_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_owner_provider_email (owner_mailbox, provider, provider_account_email),
  INDEX idx_owner_mailbox (owner_mailbox),
  INDEX idx_provider_status (provider, status)
);

CREATE TABLE IF NOT EXISTS calendar_sync_provider_settings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  provider ENUM('google','microsoft') NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  encrypted_client_secret TEXT NOT NULL,
  redirect_uri VARCHAR(512) NULL,
  scopes VARCHAR(1000) NULL,
  tenant_id VARCHAR(120) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  configured_by VARCHAR(255) NOT NULL,
  configured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by VARCHAR(255) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  crypto_key_version VARCHAR(32) NULL,
  UNIQUE KEY uq_provider (provider),
  INDEX idx_enabled (enabled)
);

CREATE TABLE IF NOT EXISTS calendar_sync_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner_mailbox VARCHAR(255) NOT NULL,
  sync_label VARCHAR(160) NOT NULL,
  dedupe_hash CHAR(64) NOT NULL,
  sync_mode ENUM('two_way','a_to_b','b_to_a') NOT NULL DEFAULT 'two_way',
  conflict_policy ENUM('newest_wins','prefer_mailcow','prefer_external','manual') NOT NULL DEFAULT 'newest_wins',
  interval_seconds INT NOT NULL DEFAULT 300,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('idle','running','error','paused','awaiting_account') NOT NULL DEFAULT 'awaiting_account',
  endpoint_a_provider ENUM('mailcow','google','microsoft') NOT NULL,
  endpoint_a_calendar_email VARCHAR(255) NOT NULL,
  endpoint_a_calendar_id VARCHAR(255) NOT NULL,
  endpoint_a_account_id BIGINT NULL,
  endpoint_b_provider ENUM('mailcow','google','microsoft') NOT NULL,
  endpoint_b_calendar_email VARCHAR(255) NOT NULL,
  endpoint_b_calendar_id VARCHAR(255) NOT NULL,
  endpoint_b_account_id BIGINT NULL,
  last_run_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_error_code VARCHAR(64) NULL,
  last_error_message VARCHAR(512) NULL,
  next_run_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_calendar_sync_jobs_endpoint_a_account
    FOREIGN KEY (endpoint_a_account_id) REFERENCES calendar_sync_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_calendar_sync_jobs_endpoint_b_account
    FOREIGN KEY (endpoint_b_account_id) REFERENCES calendar_sync_accounts(id) ON DELETE SET NULL,
  UNIQUE KEY uq_owner_dedupe_hash (owner_mailbox, dedupe_hash),
  INDEX idx_owner_mailbox (owner_mailbox),
  INDEX idx_enabled_next_run (enabled, next_run_at)
);

CREATE TABLE IF NOT EXISTS calendar_sync_event_map (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  endpoint_a_event_uid VARCHAR(255) NOT NULL,
  endpoint_b_event_uid VARCHAR(255) NOT NULL,
  endpoint_a_etag VARCHAR(255) NULL,
  endpoint_b_etag VARCHAR(255) NULL,
  endpoint_a_updated_at DATETIME NULL,
  endpoint_b_updated_at DATETIME NULL,
  last_synced_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (job_id) REFERENCES calendar_sync_jobs(id) ON DELETE CASCADE,
  UNIQUE KEY uq_job_event_pair (job_id, endpoint_a_event_uid, endpoint_b_event_uid),
  INDEX idx_job_id (job_id)
);

CREATE TABLE IF NOT EXISTS calendar_sync_audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner_mailbox VARCHAR(255) NOT NULL,
  actor VARCHAR(255) NOT NULL,
  action VARCHAR(64) NOT NULL,
  target_type ENUM('account','job','run','provider') NOT NULL,
  target_id BIGINT NULL,
  result ENUM('success','failure') NOT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_owner_created (owner_mailbox, created_at)
);

ALTER TABLE calendar_sync_audit_log
  MODIFY target_type ENUM('account','job','run','provider') NOT NULL;
