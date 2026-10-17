<?php
// ============================================================
// AUTOMATIC REMINDERS
// Your web host runs this file once a day (a "cron job").
// It checks every loan that is still out and sends the
// reminder emails that are due. See README.md, step 6.
//
// Two ways to run it:
//   1. Command line (best):  php /path/to/unit-tracker/cron.php
//   2. Web address:          https://your-site/unit-tracker/cron.php?key=YOUR_CRON_KEY
// ============================================================
require __DIR__ . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    // Called from a web address: the secret key must match config.php.
    $key = (string)config('cron_key');
    if ($key === '' || $key === 'change-this-to-something-long-and-random'
        || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo 'Reminder run ' . date('Y-m-d H:i') . "\n";
foreach (run_reminders() as $line) {
    echo $line . "\n";
}
