<?php
// ============================================================
// DATABASE UPGRADES
// Brings an existing database up to date with the code.
// The deploy from GitHub runs this on the host after every upload:
//   php migrate.php
// Each step checks first, so running it again changes nothing.
// It only works from the command line, not from a browser.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
require __DIR__ . '/includes/bootstrap.php';

function column_exists(string $table, string $column): bool
{
    return (bool)q('SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column])->fetch();
}

function table_exists(string $table): bool
{
    return (bool)q('SELECT 1 FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetch();
}

$done = 0;

// October 2026: Register (users.approved) and Forgot password (password_resets).
if (!column_exists('users', 'approved')) {
    q('ALTER TABLE users ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash');
    echo "Added users.approved\n"; $done++;
}
if (!table_exists('password_resets')) {
    q('CREATE TABLE password_resets (
         id         INT AUTO_INCREMENT PRIMARY KEY,
         user_id    INT NOT NULL,
         token_hash CHAR(64) NOT NULL UNIQUE,
         expires_at DATETIME NOT NULL,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    echo "Created table password_resets\n"; $done++;
}

// October 2026: Admin portal (users.is_admin). Magnus becomes the first admin.
if (!column_exists('users', 'is_admin')) {
    q('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER approved');
    $n = q("UPDATE users SET is_admin = 1 WHERE email = 'magnus.lundegard@gmail.com'")->rowCount();
    echo "Added users.is_admin ($n admin set)\n"; $done++;
}

echo $done ? "Database upgraded ($done change" . ($done === 1 ? '' : 's') . ").\n" : "Database already up to date.\n";
