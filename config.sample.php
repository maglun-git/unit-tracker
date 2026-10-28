<?php
// ============================================================
// SETTINGS FILE
// 1. Make a copy of this file and name the copy "config.php".
// 2. Fill in your own values below.
// Never share config.php: it holds your database password.
// ============================================================

return [

    // --- Database (you get these from your web host's control panel) ---
    'db_host' => 'localhost',
    'db_name' => 'unit_tracker',
    'db_user' => 'your_db_user',
    'db_pass' => 'your_db_password',

    // --- General ---
    'site_name' => 'Demo & POC Unit Tracker',
    // The web address where the tracker lives, WITHOUT a slash at the end.
    // Used for links inside the emails.
    'base_url'  => 'https://www.example.com/unit-tracker',
    'timezone'  => 'Europe/Stockholm',

    // How many days a loan lasts by default (you can change it per loan).
    'default_loan_days' => 30,

    // --- Who gets the emails ---
    // Your team's address. It is copied (CC) on every reminder.
    'team_email' => 'demo-team@example.com',
    // The "From" address on the emails. Use an address on your own
    // domain, otherwise the emails often end up in spam.
    'mail_from'      => 'noreply@example.com',
    'mail_from_name' => 'Unit Tracker',

    // --- How email is sent ---
    // 'smtp' = log in to a mailbox on your domain and send from it (recommended:
    //          this is what keeps emails out of spam). Fill in smtp_* below.
    // 'graph' = send from a Microsoft 365 / Office 365 mailbox that uses OAuth2
    //          log-in. Fill in graph_* below (see README, "Microsoft 365").
    // 'mail' = PHP's built-in mail() function. Easy, but often ends up in spam.
    // 'log'  = don't send, write emails to mail.log (for testing).
    // Use Admin > "Send test email" to check the settings.
    'mail_method' => 'smtp',
    // On Inleed / DirectAdmin: create the mailbox under E-mail Manager > E-mail Accounts.
    // The server is usually mail.yourdomain.com (or your server name, e.g. ns15.inleed.net).
    'smtp_host'   => 'mail.example.com',
    'smtp_port'   => 587,          // 587 with 'tls', or 465 with 'ssl'
    'smtp_secure' => 'tls',        // 'tls', 'ssl' or '' (none)
    // Use the same address as mail_from above.
    'smtp_user'   => 'noreply@example.com',
    'smtp_pass'   => 'your_email_password',

    // --- Microsoft 365 (only for 'mail_method' => 'graph') ---
    // From the app registration your IT department makes in Microsoft Entra.
    'graph_tenant_id'     => '',
    'graph_client_id'     => '',
    'graph_client_secret' => '',
    // The mailbox's log-in name, if it differs from mail_from (optional).
    'graph_user'          => '',

    // --- Reminder rules ---
    // Send an email to the holder when a loan is registered.
    'send_loan_confirmation' => true,
    // Reminders BEFORE the due date: number of days before.
    'reminder_days_before' => [7, 1],
    // Reminder ON the due date.
    'reminder_on_due_date' => true,
    // When a unit is overdue: remind again every X days.
    'overdue_repeat_days' => 7,

    // --- Secret for running reminders from a web address (cron by URL) ---
    // Change this to a long random word. See README.md.
    'cron_key' => 'change-this-to-something-long-and-random',
];
