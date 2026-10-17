<?php
// Units (cameras): list all, add a new one, or edit one.
//   units.php          -> list
//   units.php?id=new   -> form for a new unit
//   units.php?id=5     -> edit unit 5 and see its loan history
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = $_GET['id'] ?? null;
$statuses = ['available' => 'Available', 'in_repair' => 'In repair', 'retired' => 'Retired'];

// ---- Saving the form ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $data = [
        trim($_POST['model'] ?? ''),
        trim($_POST['serial_number'] ?? ''),
        trim($_POST['asset_tag'] ?? '') ?: null,
        array_key_exists($_POST['status'] ?? '', $statuses) ? $_POST['status'] : 'available',
        trim($_POST['notes'] ?? '') ?: null,
    ];
    if ($data[0] === '' || $data[1] === '') {
        flash('Model and serial number are required.', 'err');
        redirect('units.php?id=' . urlencode($id));
    }
    try {
        if ($id === 'new') {
            q('INSERT INTO units (model, serial_number, asset_tag, status, notes) VALUES (?, ?, ?, ?, ?)', $data);
        } else {
            q('UPDATE units SET model = ?, serial_number = ?, asset_tag = ?, status = ?, notes = ? WHERE id = ?',
              array_merge($data, [(int)$id]));
        }
        flash('Unit saved.');
        redirect('units.php');
    } catch (PDOException $e) {
        flash('Could not save: that serial number is probably already registered.', 'err');
        redirect('units.php?id=' . urlencode($id));
    }
}

// ---- Showing the form ----
if ($id !== null) {
    $unit = $id === 'new'
        ? ['model' => '', 'serial_number' => '', 'asset_tag' => '', 'status' => 'available', 'notes' => '']
        : q('SELECT * FROM units WHERE id = ?', [(int)$id])->fetch();
    if (!$unit) redirect('units.php');

    page_header($id === 'new' ? 'New unit' : 'Edit unit', $user);
    ?>
    <form method="post" class="card narrow">
      <?= csrf_field() ?>
      <label>Model <input name="model" value="<?= h($unit['model']) ?>" required placeholder="e.g. AXIS Q1656"></label>
      <label>Serial number <input name="serial_number" value="<?= h($unit['serial_number']) ?>" required></label>
      <label>Asset tag (optional) <input name="asset_tag" value="<?= h($unit['asset_tag']) ?>"></label>
      <label>Status
        <select name="status">
          <?php foreach ($statuses as $k => $v): ?>
            <option value="<?= $k ?>" <?= $unit['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Notes <textarea name="notes" rows="3"><?= h($unit['notes']) ?></textarea></label>
      <button>Save</button> <a href="units.php">Cancel</a>
    </form>
    <?php
    if ($id !== 'new') {
        $history = q('SELECT l.*, s.name AS person_name FROM loans l
                      JOIN salespeople s ON s.id = l.salesperson_id
                      WHERE l.unit_id = ? ORDER BY l.start_date DESC', [(int)$id])->fetchAll();
        echo '<h2>Loan history</h2>';
        if (!$history) echo '<p class="muted">This unit has never been sent out.</p>';
        else {
            echo '<table><tr><th>Status</th><th>Holder</th><th>Purpose</th><th>Out</th><th>Due</th><th>Returned</th><th></th></tr>';
            foreach ($history as $l) {
                echo '<tr><td>' . loan_badge($l) . '</td><td>' . h($l['person_name']) . '</td><td>' . h($l['purpose'])
                    . '</td><td>' . h($l['start_date']) . '</td><td>' . h($l['due_date']) . '</td><td>' . h($l['returned_date'])
                    . '</td><td><a href="loan_edit.php?id=' . $l['id'] . '">Open</a></td></tr>';
            }
            echo '</table>';
        }
    }
    page_footer();
    exit;
}

// ---- The list ----
// For each unit we also fetch who has it right now (if anyone).
$units = q('SELECT u.*, l.id AS loan_id, l.due_date, l.returned_date, s.name AS person_name
            FROM units u
            LEFT JOIN loans l ON l.unit_id = u.id AND l.returned_date IS NULL
            LEFT JOIN salespeople s ON s.id = l.salesperson_id
            ORDER BY u.model, u.serial_number')->fetchAll();

page_header('Units', $user);
?>
<p><a class="button" href="units.php?id=new">+ Add unit</a></p>
<table>
  <tr><th>Model</th><th>Serial</th><th>Asset tag</th><th>Where is it?</th><th></th></tr>
  <?php foreach ($units as $u): ?>
  <tr>
    <td><?= h($u['model']) ?></td>
    <td><?= h($u['serial_number']) ?></td>
    <td><?= h($u['asset_tag']) ?></td>
    <td>
      <?php if ($u['loan_id']): ?>
        <?= loan_badge($u) ?> with <a href="loan_edit.php?id=<?= $u['loan_id'] ?>"><?= h($u['person_name']) ?></a>
      <?php else: ?>
        <span class="badge <?= $u['status'] === 'available' ? 'green' : 'grey' ?>"><?= $statuses[$u['status']] ?></span>
      <?php endif; ?>
    </td>
    <td><a href="units.php?id=<?= $u['id'] ?>">Edit</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if (!$units) echo '<p class="muted">No units yet. Add your first one above.</p>';
page_footer();
