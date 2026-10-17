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
            . '<a href="units.php">Units</a>'
            . '<a href="people.php">Salespeople</a>'
            . '<a href="users.php">Team</a>'
            . '<a href="logout.php">Log out (' . h($user['name']) . ')</a>'
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
