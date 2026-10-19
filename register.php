<?php
// Sign-up page for people who don't have an account yet.
// The new account can be used straight away; the team address gets an
// email so you know who signed up. Admins can remove accounts in admin.php.
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
            q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
              [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            $error = 'That email already has an account. Try "Forgot password" on the log-in page.';
        }
        if (!$error) {
            // Let the team know who signed up.
            send_email(config('team_email'), 'New account: ' . $name,
                "$name ($email) created an account on " . config('site_name') . ".\n\n"
                . "Admins can see and remove accounts here:\n" . config('base_url') . "/admin.php\n");
            // Log the new person in straight away.
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            flash("Welcome, $name! Your account is ready.");
            redirect('index.php');
        }
    }
    sleep(1); // slows down automated sign-ups
}

page_header('Register');
if ($error) echo '<div class="flash err">' . h($error) . '</div>';
?>
<p>Create an account to use the tracker.</p>
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
