<?php
// ============================================================
// Every page starts by loading this file. It:
//  - reads config.php
//  - connects to the database
//  - starts the login session
//  - defines small helper functions used everywhere
// ============================================================

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    die('config.php is missing. Copy config.sample.php to config.php and fill it in.');
}
$CONFIG = require $configFile;
date_default_timezone_set($CONFIG['timezone'] ?? 'UTC');

// Read one setting: config('team_email')
function config(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

// The database connection (created the first time it is needed).
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . config('db_host') . ';dbname=' . config('db_name') . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die('Could not connect to the database. Check the db_* settings in config.php. (' . $e->getMessage() . ')');
        }
    }
    return $pdo;
}

// Run a query with "?" placeholders. Using placeholders keeps
// the database safe from malicious input (SQL injection).
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// Make text safe to show on a web page.
function h($text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// One-time messages shown at the top of the next page ("Saved!").
function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $message, 'type' => $type];
}

// ---------- Sessions & security ----------
// (Not needed when the reminder script runs from cron on the command line.)
if (PHP_SAPI !== 'cli') {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// A hidden code in every form proves the form came from our own site.
function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function check_csrf(): void
{
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        die('The form expired. Go back, reload the page and try again.');
    }
}

// Call at the top of pages that need a logged-in team member.
function require_login(): array
{
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }
    $user = q('SELECT id, name, email FROM users WHERE id = ?', [$_SESSION['user_id']])->fetch();
    if (!$user) {
        session_destroy();
        redirect('login.php');
    }
    return $user;
}

// ---------- Small date helpers ----------
function today(): string
{
    return date('Y-m-d');
}

// Days from today until $date (negative = in the past).
function days_until(string $date): int
{
    $diff = (new DateTime(today()))->diff(new DateTime($date));
    return (int)$diff->format('%r%a');
}

// A coloured label describing a loan's state.
function loan_badge(array $loan): string
{
    if (!empty($loan['returned_date'])) {
        return '<span class="badge grey">Returned</span>';
    }
    $d = days_until($loan['due_date']);
    if ($d < 0)  return '<span class="badge red">Overdue ' . (-$d) . ' d</span>';
    if ($d === 0) return '<span class="badge orange">Due today</span>';
    if ($d <= 7) return '<span class="badge orange">Due in ' . $d . ' d</span>';
    return '<span class="badge green">Out, ' . $d . ' d left</span>';
}

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/reminders.php';
