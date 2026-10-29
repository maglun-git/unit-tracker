<?php
// ============================================================
// Reminder logic: decides WHICH email a loan needs today and
// sends it. Used by cron.php (automatic) and by the
// "Send reminder now" button on a loan.
// ============================================================

// Load a loan together with its unit and salesperson.
function load_loan(int $id): ?array
{
    $row = q('SELECT l.*, u.model, u.serial_number, u.asset_tag,
                     s.name AS person_name, s.email AS person_email
              FROM loans l
              JOIN units u ON u.id = l.unit_id
              JOIN salespeople s ON s.id = l.salesperson_id
              WHERE l.id = ?', [$id])->fetch();
    return $row ?: null;
}

// Works out which reminder (if any) is due for this loan today.
// Returns a "type" like 'before_7', 'due_today', 'overdue_0', or null.
// Each type is only ever sent once per loan (see reminder_log).
function reminder_type_for(array $loan): ?string
{
    if (!empty($loan['returned_date'])) return null;
    $days = days_until($loan['due_date']);

    if ($days > 0) {
        // Pick the closest "days before" rule that we have reached.
        // Example with [7, 1]: 7..2 days left -> before_7, 1 day left -> before_1.
        $rules = config('reminder_days_before', [7, 1]);
        sort($rules);
        foreach ($rules as $n) {
            if ($days <= $n) return 'before_' . $n;
        }
        return null;
    }
    if ($days === 0) {
        return config('reminder_on_due_date', true) ? 'due_today' : null;
    }
    // Overdue: first reminder the day after the due date, then every X days.
    $repeat = max(1, (int)config('overdue_repeat_days', 7));
    $over = -$days;
    return 'overdue_' . intdiv($over - 1, $repeat);
}

// Which email template a loan email type uses (templates are edited on the E-mail page).
function template_for_type(string $type): string
{
    if ($type === 'created') return 'loan_created';
    if (strpos($type, 'before_') === 0) return 'reminder_before';
    if ($type === 'due_today') return 'reminder_due_today';
    return 'reminder_overdue';
}

// Sends one email for a loan and writes it to the log.
// $force = true sends even if this type was already sent (manual button).
function send_loan_email(array $loan, string $type, bool $force = false): bool
{
    if (!$force) {
        $already = q('SELECT 1 FROM reminder_log WHERE loan_id = ? AND reminder_type = ?', [$loan['id'], $type])->fetch();
        if ($already) return false;
    }
    $ok = send_template_email($loan['person_email'], template_for_type($type), loan_values($loan), [config('team_email')]);
    if ($ok) {
        // REPLACE also updates the time if it was sent before (manual resend).
        q('REPLACE INTO reminder_log (loan_id, reminder_type, sent_to) VALUES (?, ?, ?)',
          [$loan['id'], $type, $loan['person_email'] . ', ' . config('team_email')]);
    }
    return $ok;
}

// Sends a reminder right now, by hand (buttons on a loan and in Admin):
// the reminder that fits today, or a plain "due in X days" reminder.
function send_reminder_now(array $loan): bool
{
    $type = reminder_type_for($loan) ?? 'before_' . max(1, days_until($loan['due_date']));
    return send_loan_email($loan, $type, true);
}

// Goes through all loans that are still out and sends what is due.
// Returns a list of text lines describing what happened.
function run_reminders(): array
{
    $report = [];
    $ids = q('SELECT id FROM loans WHERE returned_date IS NULL ORDER BY due_date')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $loan = load_loan((int)$id);
        $type = reminder_type_for($loan);
        if ($type === null) continue;
        $label = "Loan #{$loan['id']} ({$loan['model']} {$loan['serial_number']} -> {$loan['person_name']}): $type";
        $already = q('SELECT 1 FROM reminder_log WHERE loan_id = ? AND reminder_type = ?', [$id, $type])->fetch();
        if ($already) continue;
        $report[] = $label . (send_loan_email($loan, $type) ? ' - sent' : ' - FAILED to send');
    }
    if (!$report) $report[] = 'Nothing to send today.';
    return $report;
}
