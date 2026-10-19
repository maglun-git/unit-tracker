<?php
// ============================================================
// Admin portal. Only accounts marked as admin can open it.
//  - Reminders: run today's reminders, or email holders right now
//  - Users: add, remove, reset password, make admin
//  - The latest emails the tracker has sent
// ============================================================
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$report = null; // lines to show after "Run today's reminders"

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    // ---------- Reminders ----------
    if ($action === 'run_reminders') {
        // Same as the daily cron job: only sends what is due and not sent yet.
        $report = run_reminders();
    } elseif ($action === 'remind_one') {
        $loan = load_loan($id);
        if ($loan && !$loan['returned_date']) {
            send_reminder_now($loan)
                ? flash('Reminder sent to ' . $loan['person_email'] . '.')
                : flash('The email could not be sent. Check the email settings in config.php.', 'err');
        }
        redirect('admin.php');
    } elseif ($action === 'remind_overdue' || $action === 'remind_all') {
        // Email every holder now. "overdue" = only units past their due date.
        $sql = 'SELECT id FROM loans WHERE returned_date IS NULL'
             . ($action === 'remind_overdue' ? ' AND due_date < ?' : '');
        $ids = q($sql, $action === 'remind_overdue' ? [today()] : [])->fetchAll(PDO::FETCH_COLUMN);
        $sent = $failed = 0;
        foreach ($ids as $loanId) {
            send_reminder_now(load_loan((int)$loanId)) ? $sent++ : $failed++;
        }
        flash("Sent $sent reminder" . ($sent === 1 ? '' : 's') . ($failed ? ", $failed failed" : '') . '.', $failed ? 'err' : 'ok');
        redirect('admin.php');

    // ---------- Users ----------
    } elseif ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('Name, valid email and a password of at least 8 characters are required.', 'err');
        } else {
            try {
                q('INSERT INTO users (name, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
                  [$name, $email, password_hash($pass, PASSWORD_DEFAULT), isset($_POST['is_admin']) ? 1 : 0]);
                if (isset($_POST['send_email'])) {
                    send_email($email, 'Your account on ' . config('site_name'),
                        "Hi $name,\n\nAn account has been made for you on " . config('site_name') . ".\n\n"
                        . "Log in here: " . config('base_url') . "/login.php\n"
                        . "Email:    $email\nPassword: $pass\n\n"
                        . "Please change the password under Team after you log in.\n");
                }
                flash("Account created for $name.");
            } catch (PDOException $e) {
                flash('That email already has an account.', 'err');
            }
        }
        redirect('admin.php');
    } elseif ($action === 'delete_user') {
        if ($id === (int)$user['id']) {
            flash('You cannot remove your own account.', 'err');
        } else {
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('Account removed.');
        }
        redirect('admin.php');
    } elseif ($action === 'toggle_admin') {
        if ($id === (int)$user['id']) {
            flash('You cannot remove your own admin rights.', 'err');
        } else {
            q('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?', [$id]);
            flash('Admin rights changed.');
        }
        redirect('admin.php');
    } elseif ($action === 'set_password') {
        $target = q('SELECT name, email FROM users WHERE id = ?', [$id])->fetch();
        $pass = $_POST['password'] ?? '';
        if (!$target || strlen($pass) < 8) {
            flash('The new password must be at least 8 characters.', 'err');
        } else {
            q('UPDATE users SET password_hash = ?, approved = 1 WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            flash("New password set for {$target['name']}. Tell them the password.");
        }
        redirect('admin.php');
    } elseif ($action === 'approve') {
        // Only for accounts that registered while sign-ups needed approval.
        q('UPDATE users SET approved = 1 WHERE id = ?', [$id]);
        flash('Account approved.');
        redirect('admin.php');
    }
}

$openLoans = q('SELECT l.id, l.due_date, l.returned_date, u.model, u.serial_number, s.name AS person_name, s.email AS person_email,
                       (SELECT MAX(sent_at) FROM reminder_log r WHERE r.loan_id = l.id) AS last_email
                FROM loans l JOIN units u ON u.id = l.unit_id JOIN salespeople s ON s.id = l.salesperson_id
                WHERE l.returned_date IS NULL ORDER BY l.due_date')->fetchAll();
$overdue = count(array_filter($openLoans, fn($l) => days_until($l['due_date']) < 0));
$users = q('SELECT * FROM users ORDER BY name')->fetchAll();
$log = q('SELECT r.sent_at, r.reminder_type, r.sent_to, r.loan_id, u.model
          FROM reminder_log r JOIN loans l ON l.id = r.loan_id JOIN units u ON u.id = l.unit_id
          ORDER BY r.sent_at DESC LIMIT 20')->fetchAll();

page_header('Admin', $user);
?>
<h2>Reminder emails</h2>
<?php if ($report !== null): ?>
<div class="card"><b>Result:</b><br><?= nl2br(h(implode("\n", $report))) ?></div>
<?php endif; ?>
<div class="card">
  <p>The daily cron job sends reminders automatically. Use these buttons to send them now.</p>
  <form method="post" class="inline">
    <?= csrf_field() ?><input type="hidden" name="action" value="run_reminders">
    <button title="Sends only the reminders that are due today and not sent yet">Run today's reminders</button>
  </form>
  <form method="post" class="inline" onsubmit="return confirm('Email every holder of an overdue unit now?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="remind_overdue">
    <button class="secondary">Remind all overdue (<?= $overdue ?>)</button>
  </form>
  <form method="post" class="inline" onsubmit="return confirm('Email every holder of a unit that is out now?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="remind_all">
    <button class="secondary">Remind everyone with a unit (<?= count($openLoans) ?>)</button>
  </form>
</div>

<?php if ($openLoans): ?>
<table>
  <tr><th>Unit</th><th>Holder</th><th>Status</th><th>Last email</th><th></th></tr>
  <?php foreach ($openLoans as $l): ?>
  <tr>
    <td><a href="loan_edit.php?id=<?= $l['id'] ?>"><?= h($l['model']) ?></a><br><small><?= h($l['serial_number']) ?></small></td>
    <td><?= h($l['person_name']) ?><br><small><?= h($l['person_email']) ?></small></td>
    <td><?= loan_badge($l) ?></td>
    <td><small><?= h($l['last_email'] ?? 'never') ?></small></td>
    <td>
      <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="remind_one"><input type="hidden" name="id" value="<?= $l['id'] ?>">
        <button class="secondary">Send reminder</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php else: ?>
<p class="muted">No units are out right now.</p>
<?php endif; ?>

<h2>Users</h2>
<table>
  <tr><th>Name</th><th>Email</th><th>Since</th><th>Admin</th><th>New password</th><th></th></tr>
  <?php foreach ($users as $u): $me = $u['id'] == $user['id']; ?>
  <tr>
    <td><?= h($u['name']) ?><?= isset($u['approved']) && !$u['approved'] ? ' <span class="badge orange">not approved</span>' : '' ?></td>
    <td><?= h($u['email']) ?></td>
    <td><?= h(substr($u['created_at'], 0, 10)) ?></td>
    <td>
      <?php if ($me): ?><span class="muted">you</span><?php else: ?>
      <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_admin"><input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="link" style="color:#1a5fd0"><?= $u['is_admin'] ? 'Yes (remove)' : 'No (make admin)' ?></button>
      </form>
      <?php endif; ?>
    </td>
    <td>
      <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="set_password"><input type="hidden" name="id" value="<?= $u['id'] ?>">
        <input type="text" name="password" minlength="8" placeholder="min 8 characters" required style="display:inline;width:150px;margin:0">
        <button class="secondary">Set</button>
      </form>
    </td>
    <td>
      <?php if (isset($u['approved']) && !$u['approved']): ?>
      <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="link" style="color:#1e7b3a">Approve</button>
      </form>
      <?php endif; ?>
      <?php if (!$me): ?>
      <form method="post" class="inline" onsubmit="return confirm('Remove <?= h($u['name']) ?>?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="link">Remove</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<form method="post" class="card narrow" style="margin-top:16px">
  <h2>Add user</h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="add_user">
  <label>Name <input name="name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Password (min 8) <input type="text" name="password" minlength="8" required></label>
  <label class="check"><input type="checkbox" name="is_admin"> Admin</label>
  <label class="check"><input type="checkbox" name="send_email" checked> Email them their log-in details</label>
  <button>Add user</button>
</form>

<h2>Latest emails sent</h2>
<?php if ($log): ?>
<table>
  <tr><th>Sent</th><th>Loan</th><th>Type</th><th>To</th></tr>
  <?php foreach ($log as $r): ?>
  <tr><td><?= h($r['sent_at']) ?></td><td><a href="loan_edit.php?id=<?= $r['loan_id'] ?>"><?= h($r['model']) ?></a></td><td><?= h($r['reminder_type']) ?></td><td><small><?= h($r['sent_to']) ?></small></td></tr>
  <?php endforeach; ?>
</table>
<?php else: ?>
<p class="muted">No emails sent yet.</p>
<?php endif; ?>
<?php page_footer();
