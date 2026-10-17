-- ============================================================
-- Demo & POC Unit Tracker - database tables
-- Run this once in phpMyAdmin (or the MySQL tool on your host).
-- ============================================================

-- People on YOUR team who can log in to the tracker.
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The cameras / units you lend out.
CREATE TABLE IF NOT EXISTS units (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  model         VARCHAR(100) NOT NULL,
  serial_number VARCHAR(100) NOT NULL UNIQUE,
  asset_tag     VARCHAR(100) NULL,
  status        ENUM('available','in_repair','retired') NOT NULL DEFAULT 'available',
  notes         TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Salespeople who borrow units. They do not log in; they get emails.
CREATE TABLE IF NOT EXISTS salespeople (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  email      VARCHAR(190) NOT NULL UNIQUE,
  region     VARCHAR(100) NULL,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row each time a unit is sent out. returned_date stays empty
-- (NULL) until the unit comes back.
CREATE TABLE IF NOT EXISTS loans (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  unit_id        INT NOT NULL,
  salesperson_id INT NOT NULL,
  purpose        ENUM('Demo','POC') NOT NULL DEFAULT 'Demo',
  customer       VARCHAR(150) NULL,
  start_date     DATE NOT NULL,
  due_date       DATE NOT NULL,
  returned_date  DATE NULL,
  notes          TEXT NULL,
  return_notes   TEXT NULL,
  created_by     INT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id)        REFERENCES units(id),
  FOREIGN KEY (salesperson_id) REFERENCES salespeople(id),
  INDEX (returned_date),
  INDEX (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Remembers which reminder emails have been sent, so nobody gets
-- the same reminder twice.
CREATE TABLE IF NOT EXISTS reminder_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  loan_id       INT NOT NULL,
  reminder_type VARCHAR(50) NOT NULL,
  sent_to       VARCHAR(255) NOT NULL,
  sent_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
  UNIQUE KEY one_per_type (loan_id, reminder_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
