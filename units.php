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
    // Added from a label photo: the form sits on the list page, so errors go back there.
    $fromPhoto = ($_POST['source'] ?? '') === 'photo';
    $back = $fromPhoto ? 'units.php' : 'units.php?id=' . urlencode($id);
    if ($fromPhoto) {
        // Serials on Axis labels are upper case, without spaces.
        $data[1] = strtoupper(preg_replace('/\s+/', '', $data[1]));
    }
    if ($data[0] === '' || $data[1] === '') {
        flash('Model and serial number are required.', 'err');
        redirect($back);
    }
    // The same serial number may only be registered once.
    $same = q('SELECT id, model FROM units WHERE serial_number = ? AND id <> ?',
              [$data[1], $id === 'new' ? 0 : (int)$id])->fetch();
    if ($same) {
        flash('Serial number ' . $data[1] . ' is already registered (' . $same['model'] . '). It is shown at the top of the list.', 'err');
        redirect('units.php?show=' . $same['id']);
    }
    try {
        if ($id === 'new') {
            q('INSERT INTO units (model, serial_number, asset_tag, status, notes) VALUES (?, ?, ?, ?, ?)', $data);
            $savedId = (int)db()->lastInsertId();
            flash(($fromPhoto ? 'Added from photo: ' : 'Added: ') . $data[0] . ', S/N ' . $data[1] . '.');
        } else {
            q('UPDATE units SET model = ?, serial_number = ?, asset_tag = ?, status = ?, notes = ? WHERE id = ?',
              array_merge($data, [(int)$id]));
            $savedId = (int)$id;
            flash('Unit saved.');
        }
        // Show the unit that was just saved at the top of the list.
        redirect('units.php?show=' . $savedId);
    } catch (PDOException $e) {
        flash('Could not save: that serial number is probably already registered.', 'err');
        redirect($back);
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

// units.php?show=5 puts unit 5 first and highlights it (used right after adding one).
$show = (int)($_GET['show'] ?? 0);
if ($show) {
    usort($units, fn($a, $b) => ($b['id'] == $show) <=> ($a['id'] == $show));
}

page_header('Units', $user);
?>
<p class="actions">
  <a class="button" href="units.php?id=new">+ Add unit</a>
  <!-- Opens the camera on a phone, or a file picker on a computer. js/label-scan.js does the rest. -->
  <label class="button secondary photo-button" for="label-photo">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
    Upload SN label photo
  </label>
  <input type="file" id="label-photo" accept="image/*" hidden>
</p>

<!-- Shown while a label photo is being read, and to check the values if the photo was not clear. -->
<div id="scan-panel" class="card narrow scan-panel" hidden>
  <img id="scan-preview" alt="The label photo">
  <p id="scan-status" class="scan-status" role="status"></p>
  <form id="scan-form" method="post" action="units.php?id=new">
    <?= csrf_field() ?>
    <input type="hidden" name="source" value="photo">
    <div id="scan-fields" hidden>
      <label>Model <input name="model" required list="known-models" placeholder="e.g. Q1951-E" autocapitalize="characters"></label>
      <label>Serial number (S/N) <input name="serial_number" required placeholder="12 characters, e.g. B8A44F27ACF5" autocapitalize="characters" autocomplete="off" spellcheck="false"></label>
      <button>Save</button> <button type="button" class="secondary" id="scan-cancel">Cancel</button>
    </div>
  </form>
  <datalist id="known-models">
    <?php foreach (array_unique(array_column($units, 'model')) as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?>
  </datalist>
</div>
<table>
  <tr><th>Model</th><th>Serial</th><th>Asset tag</th><th>Where is it?</th><th></th></tr>
  <?php foreach ($units as $u): ?>
  <tr<?= $u['id'] == $show ? ' class="just-added"' : '' ?>>
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
<?php if (!$units) echo '<p class="muted empty">No units yet. Add your first one above.</p>'; ?>
<script src="vendor/tesseract/tesseract.min.js"></script>
<script src="vendor/zxing/zxing-reader.js"></script>
<script src="js/label-scan.js"></script>
<?php page_footer();
