<?php
// ============================================================
// E-mail templates: the subject and HTML text of every email the
// tracker sends. Admins edit them on the E-mail page (emails.php);
// the edited versions are saved in the email_templates table.
// A template that has never been edited uses the default below.
//
// Placeholders like {{due_date}} are replaced with real values
// when the email is sent.
// ============================================================

// Placeholders that work in every template.
function common_placeholders(): array
{
    return [
        'site_name'   => 'Name of the tracker (site_name in config.php)',
        'site_url'    => 'Web address of the tracker',
        'sender_name' => 'Sender name (mail_from_name in config.php)',
    ];
}

// Placeholders for the emails about a loan.
function loan_placeholders(): array
{
    return [
        'first_name'        => "Holder's first name",
        'holder_name'       => "Holder's full name",
        'holder_email'      => "Holder's email",
        'unit'              => 'Model and serial, e.g. "AXIS P3265 (S/N ACCC8E123456)"',
        'model'             => 'Unit model',
        'serial'            => 'Serial number',
        'asset_tag'         => 'Asset tag (empty if none)',
        'purpose'           => 'Demo or POC',
        'customer'          => 'Customer (empty if none)',
        'start_date'        => 'Date sent out',
        'due_date'          => 'Date due back',
        'days_left_text'    => 'Days until due, e.g. "3 days" or "1 day"',
        'days_overdue_text' => 'Days overdue, e.g. "8 days"',
        'notes'             => 'Notes on the loan',
        'details'           => 'A ready-made table with unit, serial, purpose, customer and dates',
    ];
}

// The list of templates, with the text they had before they became editable.
function default_templates(): array
{
    $loanEnd = "{{details}}\n\n"
        . "<p>Need more time? Just reply to this email and we will extend the loan.</p>\n\n"
        . "<p>Best regards,<br>\n{{sender_name}}</p>\n";

    return [
        'loan_created' => [
            'name'    => 'New loan (confirmation)',
            'about'   => 'Sent to the holder when a loan is registered with "Email a confirmation" ticked. The team address gets a copy.',
            'vars'    => loan_placeholders(),
            'subject' => 'Unit sent to you: {{unit}}',
            'body'    => "<p>Hi {{first_name}},</p>\n\n"
                       . "<p>A {{purpose}} unit has been registered to you. Please return it by {{due_date}}.</p>\n\n" . $loanEnd,
        ],
        'reminder_before' => [
            'name'    => 'Reminder before due date',
            'about'   => 'Sent some days before the due date (reminder_days_before in config.php, e.g. 7 and 1 days), and by the "Send reminder now" buttons.',
            'vars'    => loan_placeholders(),
            'subject' => 'Reminder: {{unit}} is due back in {{days_left_text}}',
            'body'    => "<p>Hi {{first_name}},</p>\n\n"
                       . "<p>Friendly reminder: the unit below is due back on {{due_date}} ({{days_left_text}} from now).</p>\n\n" . $loanEnd,
        ],
        'reminder_due_today' => [
            'name'    => 'Due today',
            'about'   => 'Sent on the due date.',
            'vars'    => loan_placeholders(),
            'subject' => 'Due today: please return {{unit}}',
            'body'    => "<p>Hi {{first_name}},</p>\n\n"
                       . "<p>The unit below is due back TODAY ({{due_date}}).</p>\n\n" . $loanEnd,
        ],
        'reminder_overdue' => [
            'name'    => 'Overdue',
            'about'   => 'Sent the day after the due date, then again every few days (overdue_repeat_days in config.php) until the unit is returned.',
            'vars'    => loan_placeholders(),
            'subject' => 'OVERDUE: {{unit}} was due {{due_date}}',
            'body'    => "<p>Hi {{first_name}},</p>\n\n"
                       . "<p>The unit below is now {{days_overdue_text}} overdue (it was due {{due_date}}). Please return it, or contact us if you need more time.</p>\n\n" . $loanEnd,
        ],
        'password_reset' => [
            'name'    => 'Forgot password',
            'about'   => 'Sent when someone uses "Forgot password" on the log-in page.',
            'vars'    => [
                'name'       => "The person's name",
                'reset_link' => 'The one-time link to choose a new password (works for 1 hour)',
            ],
            'subject' => 'Reset your password',
            'body'    => "<p>Hi {{name}},</p>\n\n"
                       . "<p>Someone (hopefully you) asked to reset your password for {{site_name}}.<br>\n"
                       . "Open this link to choose a new password. It works once, for 1 hour:</p>\n\n"
                       . "<p><a href=\"{{reset_link}}\">{{reset_link}}</a></p>\n\n"
                       . "<p>If you did not ask for this, you can ignore this email.</p>\n",
        ],
        'account_created' => [
            'name'    => 'Account made by an admin',
            'about'   => 'Sent to a new team member when an admin adds them in Admin with "Email the log-in details" ticked.',
            'vars'    => [
                'name'       => "The new person's name",
                'email'      => 'Their email (used to log in)',
                'password'   => 'The password the admin chose',
                'login_link' => 'Link to the log-in page',
            ],
            'subject' => 'Your account on {{site_name}}',
            'body'    => "<p>Hi {{name}},</p>\n\n"
                       . "<p>An account has been made for you on {{site_name}}.</p>\n\n"
                       . "<p>Log in here: <a href=\"{{login_link}}\">{{login_link}}</a><br>\n"
                       . "Email: {{email}}<br>\n"
                       . "Password: {{password}}</p>\n\n"
                       . "<p>Please change the password under Team after you log in.</p>\n",
        ],
        'new_account_notice' => [
            'name'    => 'Someone registered (to the team)',
            'about'   => 'Sent to the team address when someone creates an account on the Register page.',
            'vars'    => [
                'name'       => "The new person's name",
                'email'      => 'Their email',
                'admin_link' => 'Link to the Admin page',
            ],
            'subject' => 'New account: {{name}}',
            'body'    => "<p>{{name}} ({{email}}) created an account on {{site_name}}.</p>\n\n"
                       . "<p>Admins can see and remove accounts here:<br>\n"
                       . "<a href=\"{{admin_link}}\">{{admin_link}}</a></p>\n",
        ],
        'test_email' => [
            'name'    => 'Test email',
            'about'   => 'Sent by the "Send test email" button in Admin.',
            'vars'    => [
                'sent_time' => 'Date and time the test was sent',
            ],
            'subject' => 'Test email from {{site_name}}',
            'body'    => "<p>Hi,</p>\n\n"
                       . "<p>This is a test email from {{site_name}}.<br>\n"
                       . "If you can read this, email sending works.</p>\n\n"
                       . "<p>Sent {{sent_time}}</p>\n",
        ],
    ];
}

// One template: the saved (edited) version if there is one, otherwise the default.
// Also says whether it differs from the standard text ('edited' => true/false).
function get_template(string $code): array
{
    $defaults = default_templates();
    if (!isset($defaults[$code])) {
        throw new InvalidArgumentException("Unknown email template: $code");
    }
    $template = $defaults[$code] + ['edited' => false, 'updated_at' => null, 'updated_by' => null];
    try {
        $row = q('SELECT subject, body, updated_at, updated_by FROM email_templates WHERE code = ?', [$code])->fetch();
    } catch (PDOException $e) {
        $row = false; // table not made yet (database not upgraded): use the default
    }
    if ($row) {
        $edited = $row['subject'] !== $template['subject'] || $row['body'] !== $template['body'];
        $template = array_merge($template, $row, ['edited' => $edited]);
    }
    return $template;
}

// Fills in the placeholders of a template. Returns [subject, HTML body].
function render_template(string $code, array $values): array
{
    $template = get_template($code);
    return fill_placeholders($template['subject'], $template['body'], $values);
}

// Replaces {{name}} with the value of 'name'. Values are made safe for
// HTML, except 'details', which is HTML we build ourselves.
// Placeholders without a value are left as they are.
function fill_placeholders(string $subject, string $body, array $values): array
{
    $values += [
        'site_name'   => config('site_name'),
        'site_url'    => config('base_url'),
        'sender_name' => config('mail_from_name'),
    ];
    $subjectReplace = $bodyReplace = [];
    foreach ($values as $key => $value) {
        $subjectReplace['{{' . $key . '}}'] = (string)$value;
        $bodyReplace['{{' . $key . '}}'] = $key === 'details' ? (string)$value : h($value);
    }
    $subject = strtr($subject, $subjectReplace);
    $subject = trim(preg_replace('/\s+/', ' ', strip_tags($subject))); // one line, no HTML
    return [$subject, strtr($body, $bodyReplace)];
}

// Placeholders used in a text that this template does not know (typing mistakes).
function unknown_placeholders(string $code, string $text): array
{
    $known = default_templates()[$code]['vars'] + common_placeholders();
    preg_match_all('/\{\{([^{}]*)\}\}/', $text, $m);
    return array_values(array_unique(array_diff($m[1], array_keys($known))));
}

// Renders a template and sends it as an HTML email.
function send_template_email(string $to, string $code, array $values, array $cc = [], ?array &$debug = null): bool
{
    [$subject, $body] = render_template($code, $values);
    return send_email($to, $subject, $body, $cc, $debug, true);
}

// The values for a loan email (see loan_placeholders()).
function loan_values(array $loan): array
{
    $fmt = fn($date) => date('j M Y', strtotime($date));
    $plural = fn($n) => $n . ' day' . ($n === 1 ? '' : 's');
    $days = days_until($loan['due_date']);

    // The details table. Style is written on each cell because many email
    // programs (like Outlook) ignore <style> blocks.
    $rows = ['Unit' => $loan['model'], 'Serial' => $loan['serial_number']];
    if ($loan['asset_tag']) $rows['Asset tag'] = $loan['asset_tag'];
    $rows['Purpose'] = $loan['purpose'];
    if ($loan['customer']) $rows['Customer'] = $loan['customer'];
    $rows['Sent out'] = $fmt($loan['start_date']);
    $rows['Due back'] = $fmt($loan['due_date']);
    $details = "<table cellpadding=\"0\" cellspacing=\"0\" style=\"border-collapse:collapse\">\n";
    foreach ($rows as $label => $value) {
        $details .= '<tr><td style="padding:2px 16px 2px 0;color:#6b7385">' . h($label)
                  . '</td><td style="padding:2px 0">' . h($value) . "</td></tr>\n";
    }
    $details .= '</table>';

    return [
        'first_name'        => explode(' ', trim($loan['person_name']))[0],
        'holder_name'       => $loan['person_name'],
        'holder_email'      => $loan['person_email'],
        'unit'              => $loan['model'] . ' (S/N ' . $loan['serial_number'] . ')',
        'model'             => $loan['model'],
        'serial'            => $loan['serial_number'],
        'asset_tag'         => (string)$loan['asset_tag'],
        'purpose'           => $loan['purpose'],
        'customer'          => (string)$loan['customer'],
        'start_date'        => $fmt($loan['start_date']),
        'due_date'          => $fmt($loan['due_date']),
        'days_left_text'    => $plural(max(0, $days)),
        'days_overdue_text' => $plural(max(0, -$days)),
        'notes'             => (string)($loan['notes'] ?? ''),
        'details'           => $details,
    ];
}

// Made-up values for the preview and "Send me a test" on the E-mail page.
function sample_values(string $code): array
{
    $loan = [
        'person_name' => 'Anna Svensson', 'person_email' => 'anna.svensson@example.com',
        'model' => 'AXIS P3265-LVE', 'serial_number' => 'ACCC8E123456', 'asset_tag' => 'DEMO-042',
        'purpose' => 'POC', 'customer' => 'Example Logistics AB', 'notes' => 'Includes mount and PSU.',
        'start_date' => date('Y-m-d', strtotime('-14 days')),
        'due_date' => date('Y-m-d', strtotime($code === 'reminder_overdue' ? '-8 days' : ($code === 'reminder_due_today' ? 'today' : '+7 days'))),
    ];
    $base = config('base_url');
    return array_merge(loan_values($loan), [
        'name'       => 'Anna Svensson',
        'email'      => 'anna.svensson@example.com',
        'password'   => 'example-password',
        'reset_link' => $base . '/reset.php?token=EXAMPLE',
        'login_link' => $base . '/login.php',
        'admin_link' => $base . '/admin.php',
        'sent_time'  => date('Y-m-d H:i'),
    ]);
}
