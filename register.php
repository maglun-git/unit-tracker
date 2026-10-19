<?php
// Sign-up page for people who don't have an account yet.
// New accounts start as "waiting for approval": they cannot log in
// until someone on the team approves them under Team.
require __DIR__ . '/includes/bootstrap.php';

// No accounts yet? The very first account is made in setup.php instead.
if ((int)q('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    redirect('setup.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        $error = 'Fill in a name, a valid email and a password of at least 8 characters.';
    } elseif ($pass !== ($_POST['password2'] ?? '')) {
        $error = 'The two passwords are not the same.';
    } else {
        try {
            q('INSERT INTO users (name, email, password_hash, approved) VALUES (?, ?, ?, 0)',
              [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            $error = 'That email already has an account. Try "Forgot password" on the log-in page.';
        }
        if (!$error) {
            // Tell the team someone is waiting for approval.
            send_email(config('team_email'), 'New account waiting for approval: ' . $name,
                "$name ($email) has asked for an account on " . config('site_name') . ".\n\n"
                . "Approve or reject them here:\n" . config('base_url') . "/users.php\n");
            flash('Thanks! Your account is waiting for approval. You will get an email when you can log in.');
            redirect('login.php');
        }
    }
    sleep(1); // slows down automated sign-ups
}

page_header('Register');
if ($error) echo '<div class="flash err">' . h($error) . '</div>';
?>
<p>Ask for an account. Someone on the team approves it before you can log in.</p>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <label>Your name <input name="name" value="<?= h($_POST['name'] ?? '') ?>" required autofocus></label>
  <label>Email <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required></label>
  <label>Password (min 8 characters) <input type="password" name="password" minlength="8" required></label>
  <label>Password again <input type="password" name="password2" minlength="8" required></label>
  <button>Register</button>
</form>
<p><a href="login.php">Back to log in</a></p>
<?php page_footer();
