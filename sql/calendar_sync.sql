CREATE TABLE IF NOT EXISTS calendar_sync_links (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  email_a VARCHAR(255) NOT NULL,
  email_b VARCHAR(255) NOT NULL,
  provider_a ENUM('mailcow','google','outlook') NOT NULL,
  provider_b ENUM('mailcow','google','outlook') NOT NULL,
  sync_direction ENUM('two_way','a_to_b','b_to_a') NOT NULL DEFAULT 'two_way',
  secret_hash VARCHAR(255) NOT NULL,
  status ENUM('pending_auth','active','failed','paused') NOT NULL DEFAULT 'pending_auth',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_owner(owner)
);
