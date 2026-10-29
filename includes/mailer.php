<?php
// ============================================================
// Sending email, using the PHPMailer library (includes/PHPMailer/).
// send_email() sends through your mail server (SMTP), Microsoft 365
// (Graph), or PHP's mail(), depending on 'mail_method' in config.php.
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
// $html = true: $body is HTML (from an email template). A plain-text
// copy is added too, for email programs that don't show HTML.
function send_email(string $to, string $subject, string $body, array $cc = [], ?array &$debug = null, bool $html = false): bool
{
    mail_error('');
    $cc = array_values(array_filter($cc, fn($a) => $a && strcasecmp($a, $to) !== 0));
    if ($html) {
        $body = html_email_page($body);
    }

    // Testing aid: set 'mail_method' => 'log' to write emails to a file instead.
    if (config('mail_method') === 'log') {
        $line = "=== " . date('c') . "\nTo: $to\nCc: " . implode(', ', $cc) . "\nSubject: $subject\n\n$body\n\n";
        return file_put_contents(__DIR__ . '/../mail.log', $line, FILE_APPEND) !== false;
    }

    if (config('mail_method') === 'graph') {
        return graph_send($to, $subject, $body, $cc, $debug, $html);
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
        if ($html) {
            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = html_to_text($body);
        } else {
            $mail->Body = $body;
        }
        $mail->send();
        return true;
    } catch (MailException $e) {
        mail_error($mail->ErrorInfo ?: $e->getMessage());
        error_log('Email to ' . $to . ' failed: ' . mail_error());
        return false;
    }
}

// Puts the HTML from a template into a complete email page with a
// plain, readable font. (Styles are written on the tag itself because
// many email programs ignore <style> blocks.)
function html_email_page(string $html): string
{
    return "<!doctype html>\n<html><head><meta charset=\"utf-8\"></head>\n"
        . "<body style=\"margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1d2433\">\n"
        . $html . "\n</body></html>";
}

// A plain-text version of an HTML email (links are kept as text).
function html_to_text(string $html): string
{
    $text = preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html);
    // <a href="X">Y</a> becomes "Y (X)", unless Y already is X.
    $text = preg_replace_callback('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
        $label = trim(strip_tags($m[2]));
        return ($label === '' || $label === $m[1]) ? $m[1] : "$label ({$m[1]})";
    }, $text);
    $text = preg_replace('/\s+/', ' ', $text); // line breaks in HTML source mean nothing
    $text = preg_replace('#<br\s*/?>#i', "\n", $text);
    $text = preg_replace('#</(p|div|h[1-6]|table|ul|ol)>#i', "\n\n", $text);
    $text = preg_replace('#</(tr|li)>#i', "\n", $text);
    $text = preg_replace('#</t[dh]>#i', "   ", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", $text);   // spaces at line ends
    $text = preg_replace("/\n[ \t]+/", "\n", $text);   // spaces at line starts
    $text = preg_replace("/\n{3,}/", "\n\n", $text);   // at most one empty line
    return trim($text) . "\n";
}

// ============================================================
// Microsoft 365 (Graph API)
// For mailboxes that only allow OAuth2 log-in (like Office 365).
// The tracker signs in as an "app" registered in Microsoft Entra
// (tenant id, client id and client secret in config.php), gets a
// short-lived access token, and asks Microsoft to send the email
// from the mailbox. Everything goes over normal HTTPS (port 443).
// ============================================================

// Sends a POST request and returns [HTTP status, response body].
function http_post(string $url, array $headers, string $body): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$status, $response === false ? "Connection failed: $error" : (string)$response];
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => 30, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, $response === false ? 'Connection failed' : $response];
}

// Microsoft's error text from a JSON answer, or the raw answer.
function graph_error_text(string $response): string
{
    $json = json_decode($response, true);
    return $json['error']['message'] ?? $json['error_description'] ?? $json['error'] ?? mb_substr($response, 0, 300);
}

// Gets an access token (valid about an hour; reused within one page load).
function graph_token(?array &$debug = null): ?string
{
    static $token = null, $expires = 0;
    if ($token && time() < $expires - 60) return $token;

    $url = config('graph_token_url', 'https://login.microsoftonline.com/' . rawurlencode((string)config('graph_tenant_id')) . '/oauth2/v2.0/token');
    [$status, $response] = http_post($url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => config('graph_client_id'),
        'client_secret' => config('graph_client_secret'),
        'scope'         => 'https://graph.microsoft.com/.default',
    ]));
    if ($debug !== null) $debug[] = "Sign in to Microsoft: HTTP $status";
    $json = json_decode($response, true);
    if ($status !== 200 || empty($json['access_token'])) {
        mail_error('Microsoft sign-in failed: ' . graph_error_text($response));
        return null;
    }
    $token = $json['access_token'];
    $expires = time() + (int)($json['expires_in'] ?? 3600);
    return $token;
}

function graph_send(string $to, string $subject, string $body, array $cc, ?array &$debug = null, bool $html = false): bool
{
    $token = graph_token($debug);
    if (!$token) {
        error_log('Email to ' . $to . ' failed: ' . mail_error());
        return false;
    }
    $address = fn($a) => ['emailAddress' => ['address' => $a]];
    $message = [
        'subject'      => $subject,
        'body'         => ['contentType' => $html ? 'HTML' : 'Text', 'content' => $body],
        'toRecipients' => [$address($to)],
        'ccRecipients' => array_map($address, $cc),
        'from'         => ['emailAddress' => ['address' => config('mail_from'), 'name' => (string)config('mail_from_name')]],
    ];
    if (config('team_email')) {
        $message['replyTo'] = [$address(config('team_email'))];
    }
    // The mailbox to send from: its log-in name (graph_user) or the From address.
    $mailbox = config('graph_user') ?: config('mail_from');
    $url = rtrim(config('graph_api_url', 'https://graph.microsoft.com/v1.0'), '/') . '/users/' . rawurlencode($mailbox) . '/sendMail';
    [$status, $response] = http_post($url, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['message' => $message, 'saveToSentItems' => true]));
    if ($debug !== null) $debug[] = "Send as $mailbox: HTTP $status" . ($status === 202 ? ' (accepted)' : '');
    if ($status !== 202) {
        mail_error("Microsoft did not send the email (HTTP $status): " . graph_error_text($response));
        error_log('Email to ' . $to . ' failed: ' . mail_error());
        return false;
    }
    return true;
}
