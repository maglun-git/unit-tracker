-- ============================================================
-- Upgrade for an EXISTING tracker database (October 2026):
-- adds admins and makes Magnus the first admin.
-- Run upgrade-2026-10-register.sql FIRST if you have not yet.
-- Then run this once in phpMyAdmin (SQL tab, paste, Go).
-- (New installs don't need this: install.sql already has it,
--  and the account made in setup.php becomes admin.)
-- ============================================================

-- 1 = can open the Admin page.
ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER approved;

UPDATE users SET is_admin = 1 WHERE email = 'magnus.lundegard@gmail.com';
