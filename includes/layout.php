<?php
// The top and bottom of every page (menu, styles, messages).

function page_header(string $title, ?array $user = null): void
{
    $site = h(config('site_name'));
    echo "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . " · $site</title>";
    echo '<link rel="stylesheet" href="style.css"></head>';
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    echo '<body class="page-' . h($page) . ($user ? '' : ' auth') . '">';
    // Small camera icon in front of the site name.
    $logo = '<svg class="logo" width="26" height="18" viewBox="0 0 26 18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M1 2v8h4"/><g transform="rotate(12 14 8)"><rect x="5" y="4" width="18" height="8" rx="2"/><path d="M3 2.5h22"/>'
        . '<circle cx="21" cy="8" r="1.4" fill="currentColor"/></g><circle cx="8.5" cy="7.5" r="1" fill="#5ee08a" stroke="none"/></svg>';
    echo "<header><div class=\"wrap\"><a class=\"brand\" href=\"index.php\">$logo$site</a>";
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
    // The page title, with a small line drawing on the right that matches the page.
    echo '<div class="page-title"><h1>' . h($title) . '</h1>'
        . '<span class="title-art art-' . title_art($page) . '" aria-hidden="true"></span></div>';
}

// Which drawing (img/t-*.svg) goes next to the title on each page.
function title_art(string $page): string
{
    $map = [
        'index' => 'overview', 'users' => 'overview', 'profile' => 'overview',
        'units' => 'units',
        'loans' => 'loans', 'loan_edit' => 'loans',
        'people' => 'people', 'people_import' => 'people',
        'admin' => 'admin', 'emails' => 'admin',
    ];
    return $map[$page] ?? 'units';
}

function page_footer(): void
{
    // A quiet fence-and-cameras scene along the bottom of every page.
    echo '</main><footer class="scene" aria-hidden="true"></footer></body></html>';
}
