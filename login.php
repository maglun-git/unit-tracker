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
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        redirect('index.php');
    }
    sleep(1); // slows down password guessing
    $error = 'Wrong email or password.';
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
<?php page_footer();
