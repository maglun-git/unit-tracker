<?php
// First-time setup: creates the very first team account.
// It stops working as soon as one account exists.
require __DIR__ . '/includes/bootstrap.php';

if ((int)q('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        $error = 'Fill in a name, a valid email and a password of at least 8 characters.';
    } else {
        q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
          [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        flash('Your account is ready. Log in below.');
        redirect('login.php');
    }
}

page_header('First-time setup');
if ($error) echo '<div class="flash err">' . h($error) . '</div>';
?>
<p>Create the first team account. You can add more people later under <b>Team</b>.</p>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <label>Your name <input name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Password (min 8 characters) <input type="password" name="password" minlength="8" required></label>
  <button>Create account</button>
</form>
<?php page_footer();
