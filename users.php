<?php
// Team: the people who can log in to the tracker, and changing your own password.
// Adding and removing people is done by an admin in admin.php.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'password') {
        if (strlen($_POST['password'] ?? '') < 8) {
            flash('The new password must be at least 8 characters.', 'err');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $user['id']]);
            flash('Your password was changed.');
        }
    }
    redirect('users.php');
}

$users = q('SELECT * FROM users ORDER BY name')->fetchAll();
page_header('Team', $user);
?>
<table>
  <tr><th>Name</th><th>Email</th><th>Since</th><th></th></tr>
  <?php foreach ($users as $u): ?>
  <tr>
    <td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td><td><?= h(substr($u['created_at'], 0, 10)) ?></td>
    <td>
      <?php if (!empty($u['is_admin'])): ?><span class="badge grey">admin</span><?php endif; ?>
      <?php if ($u['id'] == $user['id']): ?><span class="muted">you</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if ($user['is_admin']): ?>
<p><a href="admin.php">Add or remove people in Admin</a></p>
<?php endif; ?>

<form method="post" class="card narrow" style="margin-top:16px">
  <h2>Change my password</h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <label>New password (min 8) <input type="password" name="password" minlength="8" required></label>
  <button>Change password</button>
</form>
<?php page_footer();
