<?php
// One loan:
//   loan_edit.php        -> form to send out a unit (new loan)
//   loan_edit.php?id=12  -> see loan 12, change due date, mark returned,
//                           send a reminder by hand
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ============ Handling the buttons (POST) ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $personId = (int)($_POST['salesperson_id'] ?? 0);
        $start = $_POST['start_date'] ?? today();
        $due = $_POST['due_date'] ?? '';
        // Make sure the unit is not already out.
        $busy = q('SELECT 1 FROM loans WHERE unit_id = ? AND returned_date IS NULL', [$unitId])->fetch();
        if (!$unitId || !$personId || !$due || $due < $start) {
            flash('Choose a unit and a salesperson, and a due date after the start date.', 'err');
            redirect('loan_edit.php');
        }
        if ($busy) {
            flash('That unit is already out. Mark it as returned first.', 'err');
            redirect('loan_edit.php');
        }
        q('INSERT INTO loans (unit_id, salesperson_id, purpose, customer, start_date, due_date, notes, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $unitId, $personId,
            ($_POST['purpose'] ?? '') === 'POC' ? 'POC' : 'Demo',
            trim($_POST['customer'] ?? '') ?: null,
            $start, $due,
            trim($_POST['notes'] ?? '') ?: null,
            $user['id'],
        ]);
        $newId = (int)db()->lastInsertId();
        $msg = 'Loan registered.';
        if (!empty($_POST['notify'])) {
            $msg .= send_loan_email(load_loan($newId), 'created', true)
                ? ' A confirmation email was sent to the holder.'
                : ' BUT the confirmation email could not be sent (check the email settings).';
        }
        flash($msg);
        redirect('loan_edit.php?id=' . $newId);
    }

    $loan = load_loan($id);
    if (!$loan) redirect('loans.php');

    if ($action === 'update') {
        $due = $_POST['due_date'] ?? $loan['due_date'];
        if ($due < $loan['start_date']) {
            flash('The due date cannot be before the start date.', 'err');
            redirect('loan_edit.php?id=' . $id);
        }
        q('UPDATE loans SET purpose = ?, customer = ?, due_date = ?, notes = ? WHERE id = ?', [
            ($_POST['purpose'] ?? '') === 'POC' ? 'POC' : 'Demo',
            trim($_POST['customer'] ?? '') ?: null,
            $due,
            trim($_POST['notes'] ?? '') ?: null,
            $id,
        ]);
        // New due date = start the reminder schedule over.
        if ($due !== $loan['due_date']) {
            q("DELETE FROM reminder_log WHERE loan_id = ? AND reminder_type <> 'created'", [$id]);
        }
        flash('Loan updated.');
    } elseif ($action === 'return') {
        q('UPDATE loans SET returned_date = ?, return_notes = ? WHERE id = ?',
          [$_POST['returned_date'] ?: today(), trim($_POST['return_notes'] ?? '') ?: null, $id]);
        flash('Marked as returned. The unit is available again.');
    } elseif ($action === 'undo_return') {
        $busy = q('SELECT 1 FROM loans WHERE unit_id = ? AND returned_date IS NULL', [$loan['unit_id']])->fetch();
        if ($busy) {
            flash('Cannot undo: this unit has already been sent out again.', 'err');
        } else {
            q('UPDATE loans SET returned_date = NULL WHERE id = ?', [$id]);
            flash('The loan is active again.');
        }
    } elseif ($action === 'remind') {
        // Send the reminder that fits today, or a plain "due" reminder.
        $type = reminder_type_for($loan) ?? 'before_' . max(1, days_until($loan['due_date']));
        if (send_loan_email($loan, $type, true)) {
            flash('Reminder sent to ' . $loan['person_email'] . ' (copy to the team).');
        } else {
            flash('The email could not be sent. Check the email settings in config.php.', 'err');
        }
    } elseif ($action === 'delete') {
        q('DELETE FROM loans WHERE id = ?', [$id]);
        flash('Loan deleted.');
        redirect('loans.php');
    }
    redirect('loan_edit.php?id=' . $id);
}

// ============ New loan form ============
if (!$id) {
    $units = q("SELECT u.* FROM units u WHERE u.status = 'available'
                AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.unit_id = u.id AND l.returned_date IS NULL)
                ORDER BY u.model, u.serial_number")->fetchAll();
    $people = q('SELECT * FROM salespeople WHERE active = 1 ORDER BY name')->fetchAll();
    $defaultDue = date('Y-m-d', strtotime('+' . (int)config('default_loan_days', 30) . ' days'));

    page_header('Send out a unit', $user);
    if (!$units) echo '<div class="flash err">No units are available. <a href="units.php?id=new">Add a unit</a> or mark one as returned.</div>';
    if (!$people) echo '<div class="flash err">No salespeople yet. <a href="people.php?id=new">Add a salesperson</a> first.</div>';
    ?>
    <form method="post" class="card narrow">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <label>Unit
        <select name="unit_id" required>
          <option value="">Choose…</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>"><?= h($u['model'] . ' · ' . $u['serial_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Salesperson
        <select name="salesperson_id" required>
          <option value="">Choose…</option>
          <?php foreach ($people as $p): ?>
            <option value="<?= $p['id'] ?>"><?= h($p['name'] . ($p['region'] ? ' (' . $p['region'] . ')' : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Purpose
        <select name="purpose"><option>Demo</option><option>POC</option></select>
      </label>
      <label>Customer / project (optional) <input name="customer"></label>
      <div class="cols">
        <label>Sent out <input type="date" name="start_date" value="<?= today() ?>" required></label>
        <label>Due back <input type="date" name="due_date" value="<?= $defaultDue ?>" required></label>
      </div>
      <label>Notes (optional) <textarea name="notes" rows="2" placeholder="Accessories, shipping info…"></textarea></label>
      <label class="check"><input type="checkbox" name="notify" <?= config('send_loan_confirmation', true) ? 'checked' : '' ?>> Email the salesperson a confirmation now</label>
      <button>Register loan</button> <a href="loans.php">Cancel</a>
    </form>
    <?php
    page_footer();
    exit;
}

// ============ Existing loan ============
$loan = load_loan($id);
if (!$loan) redirect('loans.php');
$log = q('SELECT * FROM reminder_log WHERE loan_id = ? ORDER BY sent_at DESC', [$id])->fetchAll();
$creator = $loan['created_by'] ? q('SELECT name FROM users WHERE id = ?', [$loan['created_by']])->fetchColumn() : null;

page_header('Loan #' . $id, $user);
?>
<div class="card">
  <p><?= loan_badge($loan) ?></p>
  <p><b><?= h($loan['model']) ?></b> · S/N <?= h($loan['serial_number']) ?>
     <?= $loan['asset_tag'] ? ' · tag ' . h($loan['asset_tag']) : '' ?> · <a href="units.php?id=<?= $loan['unit_id'] ?>">unit history</a></p>
  <p>Holder: <b><?= h($loan['person_name']) ?></b> &lt;<?= h($loan['person_email']) ?>&gt;</p>
  <p class="muted">Sent out <?= h($loan['start_date']) ?><?= $creator ? ', registered by ' . h($creator) : '' ?>.
     <?= $loan['returned_date'] ? 'Returned ' . h($loan['returned_date']) . '.' : '' ?></p>
  <?php if ($loan['return_notes']): ?><p>Return notes: <?= nl2br(h($loan['return_notes'])) ?></p><?php endif; ?>
</div>

<div class="cols">
  <form method="post" class="card">
    <h2>Details</h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <label>Purpose
      <select name="purpose">
        <option <?= $loan['purpose'] === 'Demo' ? 'selected' : '' ?>>Demo</option>
        <option <?= $loan['purpose'] === 'POC' ? 'selected' : '' ?>>POC</option>
      </select>
    </label>
    <label>Customer / project <input name="customer" value="<?= h($loan['customer']) ?>"></label>
    <label>Due back <input type="date" name="due_date" value="<?= h($loan['due_date']) ?>" required></label>
    <small class="muted">Changing the due date (an extension) restarts the reminders.</small>
    <label>Notes <textarea name="notes" rows="3"><?= h($loan['notes']) ?></textarea></label>
    <button>Save changes</button>
  </form>

  <div>
  <?php if (!$loan['returned_date']): ?>
    <form method="post" class="card">
      <h2>Unit came back</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="return">
      <label>Returned on <input type="date" name="returned_date" value="<?= today() ?>"></label>
      <label>Condition / notes <textarea name="return_notes" rows="2" placeholder="All accessories included?"></textarea></label>
      <button>Mark as returned</button>
    </form>
    <form method="post" class="card">
      <h2>Reminder</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="remind">
      <p class="muted">Reminders go out automatically. Use this to send one right now.</p>
      <button>Send reminder now</button>
    </form>
  <?php else: ?>
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="undo_return">
      <p>Marked as returned by mistake?</p>
      <button class="secondary">Undo return</button>
    </form>
  <?php endif; ?>
    <form method="post" class="card" onsubmit="return confirm('Delete this loan for good? Use this only for mistakes.')">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="danger">Delete loan</button>
    </form>
  </div>
</div>

<h2>Emails sent</h2>
<?php if (!$log): ?>
  <p class="muted">No emails sent for this loan yet.</p>
<?php else: ?>
<table>
  <tr><th>When</th><th>Type</th><th>To</th></tr>
  <?php foreach ($log as $r): ?>
    <tr><td><?= h($r['sent_at']) ?></td><td><?= h($r['reminder_type']) ?></td><td><?= h($r['sent_to']) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif;
page_footer();
