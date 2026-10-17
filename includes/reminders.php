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

// Builds the subject and text of an email for a loan.
function build_email(array $loan, string $type): array
{
    $unit = $loan['model'] . ' (S/N ' . $loan['serial_number'] . ')';
    $due = date('j M Y', strtotime($loan['due_date']));
    $days = days_until($loan['due_date']);
    $first = explode(' ', trim($loan['person_name']))[0];

    if ($type === 'created') {
        $subject = "Unit sent to you: $unit";
        $intro = "A {$loan['purpose']} unit has been registered to you. Please return it by $due.";
    } elseif (strpos($type, 'before_') === 0) {
        $subject = "Reminder: $unit is due back in $days day" . ($days === 1 ? '' : 's');
        $intro = "Friendly reminder: the unit below is due back on $due ($days day" . ($days === 1 ? '' : 's') . " from now).";
    } elseif ($type === 'due_today') {
        $subject = "Due today: please return $unit";
        $intro = "The unit below is due back TODAY ($due).";
    } else {
        $over = -$days;
        $subject = "OVERDUE: $unit was due $due";
        $intro = "The unit below is now $over day" . ($over === 1 ? '' : 's') . " overdue (it was due $due). Please return it, or contact us if you need more time.";
    }

    $body = "Hi $first,\n\n$intro\n\n"
        . "Unit:      {$loan['model']}\n"
        . "Serial:    {$loan['serial_number']}\n"
        . ($loan['asset_tag'] ? "Asset tag: {$loan['asset_tag']}\n" : '')
        . "Purpose:   {$loan['purpose']}\n"
        . ($loan['customer'] ? "Customer:  {$loan['customer']}\n" : '')
        . "Sent out:  " . date('j M Y', strtotime($loan['start_date'])) . "\n"
        . "Due back:  $due\n\n"
        . "Need more time? Just reply to this email and we will extend the loan.\n\n"
        . "Best regards,\n" . config('mail_from_name') . "\n";

    return [$subject, $body];
}

// Sends one email for a loan and writes it to the log.
// $force = true sends even if this type was already sent (manual button).
function send_loan_email(array $loan, string $type, bool $force = false): bool
{
    if (!$force) {
        $already = q('SELECT 1 FROM reminder_log WHERE loan_id = ? AND reminder_type = ?', [$loan['id'], $type])->fetch();
        if ($already) return false;
    }
    [$subject, $body] = build_email($loan, $type);
    $ok = send_email($loan['person_email'], $subject, $body, [config('team_email')]);
    if ($ok) {
        // REPLACE also updates the time if it was sent before (manual resend).
        q('REPLACE INTO reminder_log (loan_id, reminder_type, sent_to) VALUES (?, ?, ?)',
          [$loan['id'], $type, $loan['person_email'] . ', ' . config('team_email')]);
    }
    return $ok;
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
