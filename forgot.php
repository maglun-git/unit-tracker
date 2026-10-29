<?php
// "Forgot password": emails a one-time link to choose a new password.
// The link works once and stops working after 1 hour.
require __DIR__ . '/includes/bootstrap.php';

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $user = q('SELECT id, name, email FROM users WHERE email = ? AND approved = 1',
              [trim($_POST['email'] ?? '')])->fetch();
    if ($user) {
        // A long random code. Only a fingerprint (hash) of it is saved, so a
        // leaked database cannot be used to reset passwords.
        $token = bin2hex(random_bytes(32));
        q('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]); // old links stop working
        q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
          [$user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
        send_template_email($user['email'], 'password_reset', [
            'name' => $user['name'], 'reset_link' => config('base_url') . '/reset.php?token=' . $token,
        ]);
    }
    sleep(1); // slows down guessing
    // Same answer whether or not the email exists, so nobody can use
    // this page to find out who has an account.
    $sent = true;
}

page_header('Forgot password');
if ($sent): ?>
<div class="card narrow">
  <p>If that email has an account, we have sent it a link to reset the password. Check your inbox (and the spam folder).</p>
  <p><a href="login.php">Back to log in</a></p>
</div>
<?php else: ?>
<p>Enter your email and we will send you a link to choose a new password.</p>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" required autofocus></label>
  <button>Send reset link</button>
</form>
<p><a href="login.php">Back to log in</a></p>
<?php endif;
page_footer();
