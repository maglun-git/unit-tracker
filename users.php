<?php
// Team: the people who can log in to the tracker.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('Name, valid email and a password of at least 8 characters are required.', 'err');
        } else {
            try {
                q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
                  [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                flash("Account created for $name. Tell them their password.");
            } catch (PDOException $e) {
                flash('That email already has an account.', 'err');
            }
        }
    } elseif ($action === 'password') {
        if (strlen($_POST['password'] ?? '') < 8) {
            flash('The new password must be at least 8 characters.', 'err');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $user['id']]);
            flash('Your password was changed.');
        }
    } elseif ($action === 'delete') {
        $target = (int)($_POST['id'] ?? 0);
        if ($target === (int)$user['id']) {
            flash('You cannot remove your own account.', 'err');
        } else {
            q('DELETE FROM users WHERE id = ?', [$target]);
            flash('Account removed.');
        }
    }
    redirect('users.php');
}

$users = q('SELECT id, name, email, created_at FROM users ORDER BY name')->fetchAll();
page_header('Team', $user);
?>
<table>
  <tr><th>Name</th><th>Email</th><th>Since</th><th></th></tr>
  <?php foreach ($users as $u): ?>
  <tr>
    <td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td><td><?= h(substr($u['created_at'], 0, 10)) ?></td>
    <td>
      <?php if ($u['id'] != $user['id']): ?>
      <form method="post" onsubmit="return confirm('Remove this account?')" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="link">Remove</button>
      </form>
      <?php else: ?><span class="muted">you</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<div class="cols">
  <form method="post" class="card">
    <h2>Add team member</h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Name <input name="name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <label>Password (min 8) <input type="password" name="password" minlength="8" required></label>
    <button>Add</button>
  </form>
  <form method="post" class="card">
    <h2>Change my password</h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <label>New password (min 8) <input type="password" name="password" minlength="8" required></label>
    <button>Change password</button>
  </form>
</div>
<?php page_footer();
