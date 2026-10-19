<?php
// Log-in page for team members.
require __DIR__ . '/includes/bootstrap.php';

// No accounts yet? Send the visitor to the first-time setup.
if ((int)q('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    redirect('setup.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $user = q('SELECT * FROM users WHERE email = ?', [trim($_POST['email'] ?? '')])->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        if (isset($user['approved']) && !$user['approved']) {
            $error = 'Your account is waiting for approval by the team. You will get an email when it is approved.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            redirect('index.php');
        }
    } else {
        sleep(1); // slows down password guessing
        $error = 'Wrong email or password.';
    }
}

page_header('Log in');
if ($error) echo '<div class="flash err">' . h($error) . '</div>';
?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" required autofocus></label>
  <label>Password <input type="password" name="password" required></label>
  <button>Log in</button>
</form>
<p class="narrow"><a href="forgot.php">Forgot password?</a> · No account? <a href="register.php">Register here</a></p>
<?php page_footer();
