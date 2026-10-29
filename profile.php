<?php
// Profile: the logged-in person edits their own name, email, title,
// phone number and password.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'details') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Fill in your name and a valid email.', 'err');
        } elseif (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])->fetch()) {
            flash('Another account already uses that email.', 'err');
        } else {
            q('UPDATE users SET name = ?, email = ?, title = ?, phone = ? WHERE id = ?',
              [$name, $email, $title ?: null, $phone ?: null, $user['id']]);
            flash('Your profile was saved.');
        }
    }

    if ($action === 'password') {
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!password_verify($_POST['current'] ?? '', $row['password_hash'])) {
            flash('Your current password was not right.', 'err');
        } elseif (strlen($_POST['password'] ?? '') < 8) {
            flash('The new password must be at least 8 characters.', 'err');
        } elseif ($_POST['password'] !== ($_POST['password2'] ?? '')) {
            flash('The two new passwords are not the same.', 'err');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $user['id']]);
            flash('Your password was changed.');
        }
    }
    redirect('profile.php');
}

// Read the full row (require_login() only returns a few fields).
$me = q('SELECT * FROM users WHERE id = ?', [$user['id']])->fetch();
page_header('My profile', $user);
?>
<form method="post" class="card narrow">
  <h2>My details</h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="details">
  <label>Name <input name="name" value="<?= h($me['name']) ?>" required></label>
  <label>Email (also your log-in) <input type="email" name="email" value="<?= h($me['email']) ?>" required></label>
  <label>Title <input name="title" value="<?= h($me['title'] ?? '') ?>" placeholder="e.g. Sales Engineer"></label>
  <label>Phone <input name="phone" value="<?= h($me['phone'] ?? '') ?>"></label>
  <button>Save</button>
</form>

<form method="post" class="card narrow" style="margin-top:16px">
  <h2>Change my password</h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <label>Current password <input type="password" name="current" required></label>
  <label>New password (min 8) <input type="password" name="password" minlength="8" required></label>
  <label>New password again <input type="password" name="password2" minlength="8" required></label>
  <button>Change password</button>
</form>
<p class="muted">Member since <?= h(substr($me['created_at'], 0, 10)) ?><?= $user['is_admin'] ? ' · admin' : '' ?></p>
<?php page_footer();
