<?php
// ============================================================
// Sending email.
// send_email() picks PHP's mail() or SMTP based on config.php.
// Returns true when the email was handed over successfully.
// ============================================================

function send_email(string $to, string $subject, string $body, array $cc = []): bool
{
    $cc = array_values(array_filter($cc, fn($a) => $a && strcasecmp($a, $to) !== 0));

    // Testing aid: set 'mail_method' => 'log' to write emails to a file instead.
    if (config('mail_method') === 'log') {
        $line = "=== " . date('c') . "\nTo: $to\nCc: " . implode(', ', $cc) . "\nSubject: $subject\n\n$body\n\n";
        return file_put_contents(__DIR__ . '/../mail.log', $line, FILE_APPEND) !== false;
    }
    if (config('mail_method') === 'smtp') {
        return smtp_send($to, $cc, $subject, $body);
    }

    // PHP's built-in mail()
    $from = config('mail_from');
    $headers = [
        'From: ' . encode_header(config('mail_from_name')) . " <$from>",
        'Reply-To: ' . config('team_email'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($cc) {
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }
    return mail($to, encode_header($subject), $body, implode("\r\n", $headers), '-f' . $from);
}

// Lets subjects and names contain characters like å, ä, ö.
function encode_header(string $text): string
{
    return preg_match('/[^\x20-\x7E]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

// A small SMTP client: talks to the mail server step by step.
function smtp_send(string $to, array $cc, string $subject, string $body): bool
{
    $host = config('smtp_host');
    $port = (int)config('smtp_port', 587);
    $secure = config('smtp_secure', 'tls');
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $sock = @stream_socket_client($remote, $errno, $errstr, 20);
    if (!$sock) {
        error_log("SMTP connect failed: $errstr");
        return false;
    }
    stream_set_timeout($sock, 20);

    // Send one command and check the server's answer code.
    $cmd = function (?string $line, string $expect) use ($sock): bool {
        if ($line !== null) {
            fwrite($sock, $line . "\r\n");
        }
        $reply = '';
        while (($l = fgets($sock, 515)) !== false) {
            $reply .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break; // last line of the reply
        }
        if (strpos($reply, $expect) !== 0) {
            error_log("SMTP error after '" . ($line ?? 'connect') . "': $reply");
            return false;
        }
        return true;
    };

    $me = gethostname() ?: 'localhost';
    $ok = $cmd(null, '220') && $cmd("EHLO $me", '250');
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', '220')
            && stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            && $cmd("EHLO $me", '250');
    }
    if ($ok && config('smtp_user')) {
        $ok = $cmd('AUTH LOGIN', '334')
            && $cmd(base64_encode(config('smtp_user')), '334')
            && $cmd(base64_encode(config('smtp_pass')), '235');
    }
    $from = config('mail_from');
    $ok = $ok && $cmd("MAIL FROM:<$from>", '250');
    foreach (array_merge([$to], $cc) as $rcpt) {
        $ok = $ok && $cmd("RCPT TO:<$rcpt>", '25');
    }
    if ($ok) {
        $headers = 'Date: ' . date('r') . "\r\n"
            . 'From: ' . encode_header(config('mail_from_name')) . " <$from>\r\n"
            . "To: <$to>\r\n"
            . ($cc ? 'Cc: ' . implode(', ', $cc) . "\r\n" : '')
            . 'Reply-To: ' . config('team_email') . "\r\n"
            . 'Subject: ' . encode_header($subject) . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        // Lines starting with "." must be doubled in SMTP.
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $body));
        $ok = $cmd('DATA', '354') && $cmd($headers . "\r\n" . $data . "\r\n.", '250');
    }
    $cmd('QUIT', '221');
    fclose($sock);
    return $ok;
}
