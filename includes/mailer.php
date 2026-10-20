<?php
// ============================================================
// Sending email, using the PHPMailer library (includes/PHPMailer/).
// send_email() sends through your mail server (SMTP) or PHP's
// mail(), depending on 'mail_method' in config.php.
// Returns true when the email was handed over successfully.
// If it fails, mail_error() tells why.
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

// The reason the last email failed ('' if it worked).
function mail_error(?string $set = null): string
{
    static $error = '';
    if ($set !== null) $error = $set;
    return $error;
}

// $debug: pass an array to collect the conversation with the mail
// server (used by the "Send test email" button in Admin).
function send_email(string $to, string $subject, string $body, array $cc = [], ?array &$debug = null): bool
{
    mail_error('');
    $cc = array_values(array_filter($cc, fn($a) => $a && strcasecmp($a, $to) !== 0));

    // Testing aid: set 'mail_method' => 'log' to write emails to a file instead.
    if (config('mail_method') === 'log') {
        $line = "=== " . date('c') . "\nTo: $to\nCc: " . implode(', ', $cc) . "\nSubject: $subject\n\n$body\n\n";
        return file_put_contents(__DIR__ . '/../mail.log', $line, FILE_APPEND) !== false;
    }

    $mail = new PHPMailer(true); // true = report problems as errors we can catch
    try {
        if (config('mail_method') === 'smtp') {
            // Log in to the mail server, like an email program does.
            // This is what keeps emails out of spam: they really come from your mailbox.
            $mail->isSMTP();
            $mail->Host = config('smtp_host');
            $mail->Port = (int)config('smtp_port', 587);
            $secure = config('smtp_secure', 'tls');
            $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS
                              : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $mail->SMTPAutoTLS = $secure !== '';
            if (config('smtp_user')) {
                $mail->SMTPAuth = true;
                $mail->Username = config('smtp_user');
                $mail->Password = config('smtp_pass');
            }
            $mail->Timeout = 20;
            // Give up after 30 seconds if the server stops answering (default is 5 minutes).
            $mail->getSMTPInstance()->Timelimit = 30;
            if ($debug !== null) {
                $mail->SMTPDebug = 2; // passwords are hidden by PHPMailer
                $mail->Debugoutput = function ($line) use (&$debug) { $debug[] = trim($line); };
            }
        } else {
            $mail->isMail(); // PHP's built-in mail()
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        // "From" and the bounce address (Return-Path) are both your own address.
        // Mail servers check that these match your domain's SPF/DKIM records.
        $mail->setFrom(config('mail_from'), (string)config('mail_from_name'));
        $mail->Sender = config('mail_from');
        if (config('team_email')) {
            $mail->addReplyTo(config('team_email'));
        }
        $mail->addAddress($to);
        foreach ($cc as $address) {
            $mail->addCC($address);
        }
        $mail->XMailer = ' '; // don't advertise the software; some filters dislike it
        $mail->Subject = $subject;
        $mail->Body = $body; // plain text: simple emails score well with spam filters
        $mail->send();
        return true;
    } catch (MailException $e) {
        mail_error($mail->ErrorInfo ?: $e->getMessage());
        error_log('Email to ' . $to . ' failed: ' . mail_error());
        return false;
    }
}
