<?php
// The top and bottom of every page (menu, styles, messages).

function page_header(string $title, ?array $user = null): void
{
    $site = h(config('site_name'));
    echo "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . " · $site</title>";
    echo '<link rel="stylesheet" href="style.css"></head><body>';
    echo "<header><div class=\"wrap\"><a class=\"brand\" href=\"index.php\">$site</a>";
    if ($user) {
        echo '<nav>'
            . '<a href="index.php">Overview</a>'
            . '<a href="loans.php">Loans</a>'
            . (!empty($user['is_admin']) ? '<a href="emails.php">E-mail</a>' : '')
            . '<a href="units.php">Units</a>'
            . '<a href="people.php">Salespeople</a>'
            . '<a href="users.php">Team</a>'
            . (!empty($user['is_admin']) ? '<a href="admin.php">Admin</a>' : '')
            . '<a href="profile.php" title="' . h($user['name']) . '">Profile (' . h(strtok($user['name'], ' ')) . ')</a>'
            . '<a class="logout" href="logout.php" title="Log out" aria-label="Log out">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></a>'
            . '</nav>';
    }
    echo '</div></header><main class="wrap">';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
    unset($_SESSION['flash']);
    echo '<h1>' . h($title) . '</h1>';
}

function page_footer(): void
{
    echo '</main></body></html>';
}
