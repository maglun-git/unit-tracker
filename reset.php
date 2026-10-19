<?php
// Choose a new password, using the link from the "Forgot password" email.
require __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$reset = $token === '' ? false : q(
    'SELECT r.id, r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
     WHERE r.token_hash = ? AND r.expires_at > ? AND u.approved = 1',
    [hash('sha256', $token), date('Y-m-d H:i:s')])->fetch();

$error = '';
if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pass = $_POST['password'] ?? '';
    if (strlen($pass) < 8) {
        $error = 'The new password must be at least 8 characters.';
    } elseif ($pass !== ($_POST['password2'] ?? '')) {
        $error = 'The two passwords are not the same.';
    } else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $reset['user_id']]);
        q('DELETE FROM password_resets WHERE user_id = ?', [$reset['user_id']]); // the link is used up
        flash('Your password was changed. Log in with the new one.');
        redirect('login.php');
    }
}

page_header('Choose a new password');
if (!$reset): ?>
<div class="card narrow">
  <p>This reset link is not valid. It may have been used already or be more than 1 hour old.</p>
  <p><a href="forgot.php">Send a new link</a></p>
</div>
<?php else:
if ($error) echo '<div class="flash err">' . h($error) . '</div>'; ?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <label>New password (min 8 characters) <input type="password" name="password" minlength="8" required autofocus></label>
  <label>New password again <input type="password" name="password2" minlength="8" required></label>
  <button>Save new password</button>
</form>
<?php endif;
page_footer();
