CREATE TABLE IF NOT EXISTS calendar_sync_accounts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner_mailbox VARCHAR(255) NOT NULL,
  provider ENUM('google','microsoft') NOT NULL,
  provider_account_id VARCHAR(255) NOT NULL,
  encrypted_access_token TEXT NOT NULL,
  encrypted_refresh_token TEXT NOT NULL,
  token_expires_at DATETIME NULL,
  scopes TEXT NOT NULL,
  status ENUM('active','revoked','error') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_owner_provider_account (owner_mailbox, provider, provider_account_id),
  INDEX idx_owner_mailbox (owner_mailbox)
);

CREATE TABLE IF NOT EXISTS calendar_sync_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner_mailbox VARCHAR(255) NOT NULL,
  mailcow_calendar_id VARCHAR(255) NOT NULL,
  external_account_id BIGINT NOT NULL,
  external_calendar_id VARCHAR(255) NOT NULL,
  direction ENUM('two_way','mailcow_to_external','external_to_mailcow') NOT NULL,
  conflict_policy ENUM('newest_wins','prefer_mailcow','prefer_external','manual') NOT NULL DEFAULT 'newest_wins',
  interval_seconds INT NOT NULL DEFAULT 300,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('idle','running','error','paused') NOT NULL DEFAULT 'idle',
  last_run_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_error_code VARCHAR(64) NULL,
  last_error_message VARCHAR(512) NULL,
  next_run_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (external_account_id) REFERENCES calendar_sync_accounts(id) ON DELETE CASCADE,
  INDEX idx_owner_mailbox (owner_mailbox),
  INDEX idx_enabled_next_run (enabled, next_run_at)
);

CREATE TABLE IF NOT EXISTS calendar_sync_event_map (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  mailcow_event_uid VARCHAR(255) NOT NULL,
  external_event_uid VARCHAR(255) NOT NULL,
  mailcow_etag VARCHAR(255) NULL,
  external_etag VARCHAR(255) NULL,
  mailcow_updated_at DATETIME NULL,
  external_updated_at DATETIME NULL,
  last_synced_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (job_id) REFERENCES calendar_sync_jobs(id) ON DELETE CASCADE,
  UNIQUE KEY uq_job_event_pair (job_id, mailcow_event_uid, external_event_uid),
  INDEX idx_job_id (job_id)
);

CREATE TABLE IF NOT EXISTS calendar_sync_audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner_mailbox VARCHAR(255) NOT NULL,
  actor VARCHAR(255) NOT NULL,
  action VARCHAR(64) NOT NULL,
  target_type ENUM('account','job','run') NOT NULL,
  target_id BIGINT NULL,
  result ENUM('success','failure') NOT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_owner_created (owner_mailbox, created_at)
);
