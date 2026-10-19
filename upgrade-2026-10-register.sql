-- ============================================================
-- Upgrade for an EXISTING tracker database (October 2026):
-- adds self-registration (with approval) and "Forgot password".
-- Run this once in phpMyAdmin: open your database, click the
-- SQL tab, paste everything below and click Go.
-- (New installs don't need this: install.sql already has it.)
-- ============================================================

-- 1 = can log in, 0 = registered but waiting for approval.
-- Everyone who already has an account gets 1.
ALTER TABLE users ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash;

-- One row per "Forgot password" link that has been emailed.
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
