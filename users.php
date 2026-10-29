<?php
// Team: the people who can log in to the tracker.
// Your own details and password are changed in profile.php.
// Adding and removing people is done by an admin in admin.php.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$users = q('SELECT * FROM users ORDER BY name')->fetchAll();
page_header('Team', $user);
?>
<table>
  <tr><th>Name</th><th>Title</th><th>Email</th><th>Phone</th><th>Since</th><th></th></tr>
  <?php foreach ($users as $u): ?>
  <tr>
    <td><?= h($u['name']) ?></td><td><?= h($u['title'] ?? '') ?></td><td><?= h($u['email']) ?></td><td><?= h($u['phone'] ?? '') ?></td><td><?= h(substr($u['created_at'], 0, 10)) ?></td>
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

<p><a href="profile.php">Edit my own details or password</a></p>
<?php page_footer();
