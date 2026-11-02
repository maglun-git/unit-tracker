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
    if (($_POST['source'] ?? '') === 'photo') {
        save_units_from_photos();
    }
    $data = [
        trim($_POST['model'] ?? ''),
        trim($_POST['serial_number'] ?? ''),
        trim($_POST['asset_tag'] ?? '') ?: null,
        array_key_exists($_POST['status'] ?? '', $statuses) ? $_POST['status'] : 'available',
        trim($_POST['notes'] ?? '') ?: null,
        strtoupper(trim($_POST['part_number'] ?? '')) ?: null,
    ];
    if ($data[0] === '' || $data[1] === '') {
        flash('Model and serial number are required.', 'err');
        redirect('units.php?id=' . urlencode($id));
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
            q('INSERT INTO units (model, serial_number, asset_tag, status, notes, part_number) VALUES (?, ?, ?, ?, ?, ?)', $data);
            $savedId = (int)db()->lastInsertId();
            flash('Added: ' . $data[0] . ', S/N ' . $data[1] . '.');
        } else {
            q('UPDATE units SET model = ?, serial_number = ?, asset_tag = ?, status = ?, notes = ?, part_number = ? WHERE id = ?',
              array_merge($data, [(int)$id]));
            $savedId = (int)$id;
            flash('Unit saved.');
        }
        // Show the unit that was just saved at the top of the list.
        redirect('units.php?show=' . $savedId);
    } catch (PDOException $e) {
        flash('Could not save: that serial number is probably already registered.', 'err');
        redirect('units.php?id=' . urlencode($id));
    }
}

// Units read from one or more label photos (the "Upload SN label photo" button).
// The form sends lists: model[], serial_number[] and part_number[], one of each per photo.
function save_units_from_photos(): void
{
    $models = (array)($_POST['model'] ?? []);
    $serials = (array)($_POST['serial_number'] ?? []);
    $parts = (array)($_POST['part_number'] ?? []);
    $added = [];     // "Q1951-E (B8A44F27ACF5)" for the message
    $problems = [];  // why some could not be added
    $show = [];      // unit ids to show at the top of the list
    foreach ($models as $i => $model) {
        $model = trim((string)$model);
        // Serials on Axis labels are upper case, without spaces.
        $serial = strtoupper(preg_replace('/\s+/', '', (string)($serials[$i] ?? '')));
        // The P/N from the label (e.g. 02150-001-01), so the model is known next time.
        $part = strtoupper(trim((string)($parts[$i] ?? ''))) ?: null;
        if ($model === '' && $serial === '') continue;
        if ($model === '' || $serial === '') {
            $problems[] = 'photo ' . ($i + 1) . ' needs both a model and a serial number';
            continue;
        }
        // The same serial number may only be registered once.
        $same = q('SELECT id, model FROM units WHERE serial_number = ?', [$serial])->fetch();
        if ($same) {
            $problems[] = "S/N $serial is already registered ({$same['model']})";
            $show[] = (int)$same['id'];
            continue;
        }
        try {
            q('INSERT INTO units (model, serial_number, part_number, status) VALUES (?, ?, ?, ?)', [$model, $serial, $part, 'available']);
            $show[] = (int)db()->lastInsertId();
            $added[] = "$model ($serial)";
        } catch (PDOException $e) {
            $problems[] = "S/N $serial could not be saved";
        }
    }
    if ($added) {
        flash((count($added) === 1 ? 'Added from photo: ' : 'Added ' . count($added) . ' units from photos: ')
            . implode(', ', $added) . '.');
    }
    if ($problems) {
        flash('Not added: ' . implode('; ', $problems) . '.', 'err');
    }
    if (!$added && !$problems) {
        flash('Nothing to add.', 'err');
    }
    redirect('units.php' . ($show ? '?show=' . implode(',', array_unique($show)) : ''));
}

// ---- Showing the form ----
if ($id !== null) {
    $unit = $id === 'new'
        ? ['model' => '', 'serial_number' => '', 'part_number' => '', 'asset_tag' => '', 'status' => 'available', 'notes' => '']
        : q('SELECT * FROM units WHERE id = ?', [(int)$id])->fetch();
    if (!$unit) redirect('units.php');

    page_header($id === 'new' ? 'New unit' : 'Edit unit', $user);
    ?>
    <form method="post" class="card narrow">
      <?= csrf_field() ?>
      <label>Model <input name="model" value="<?= h($unit['model']) ?>" required placeholder="e.g. Q1971-E 7mm 8.3fps"></label>
      <label>Serial number <input name="serial_number" value="<?= h($unit['serial_number']) ?>" required></label>
      <label>Part number, P/N (optional) <input name="part_number" value="<?= h($unit['part_number'] ?? '') ?>" placeholder="e.g. 02150-001-01"></label>
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

// units.php?show=5,6 puts units 5 and 6 first, in that order, and highlights them
// (used right after adding units).
$show = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['show'] ?? '')))));
if ($show) {
    $rank = array_flip($show);
    // usort keeps the rest in the normal order (model, serial).
    usort($units, fn($a, $b) => ($rank[$a['id']] ?? PHP_INT_MAX) <=> ($rank[$b['id']] ?? PHP_INT_MAX));
}

page_header('Units', $user);
?>
<p class="actions">
  <a class="button" href="units.php?id=new">+ Add unit</a>
  <!-- Opens the camera on a phone, or a file picker on a computer (several photos at once).
       Photos can also be dragged onto the page. js/label-scan.js does the rest. -->
  <label class="button secondary photo-button" for="label-photo">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
    Upload SN label photo
  </label>
  <input type="file" id="label-photo" accept="image/*" multiple hidden>
  <span class="muted drop-hint">or drag label photos onto this page</span>
</p>

<!-- Shown while label photos are read: one row per photo, to check before saving if needed. -->
<div id="scan-panel" class="card scan-panel" hidden>
  <p id="scan-status" class="scan-status" role="status"></p>
  <form id="scan-form" method="post" action="units.php">
    <?= csrf_field() ?>
    <input type="hidden" name="source" value="photo">
    <div id="scan-items"></div>
    <div id="scan-buttons" hidden>
      <button id="scan-save">Save all</button> <button type="button" class="secondary" id="scan-cancel">Cancel</button>
    </div>
  </form>
  <datalist id="known-models">
    <?php foreach (array_unique(array_column($units, 'model')) as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?>
  </datalist>
</div>
<div id="drop-overlay" class="drop-overlay" hidden><div>Drop label photos here.<br><small>Each photo becomes one unit.</small></div></div>
<table>
  <tr><th>Model</th><th>Serial</th><th>Asset tag</th><th>Where is it?</th><th></th></tr>
  <?php foreach ($units as $u): ?>
  <tr<?= in_array((int)$u['id'], $show, true) ? ' class="just-added"' : '' ?>>
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
<?php
// Part numbers already registered and their model, newest first, so a label photo
// with a known P/N gets the same model text: {"02150-001": "Q1951-E 7mm 30fps"}.
$knownParts = [];
foreach (q('SELECT part_number, model FROM units WHERE part_number IS NOT NULL ORDER BY id DESC')->fetchAll() as $p) {
    $knownParts[substr($p['part_number'], 0, 9)] ??= $p['model'];
}
?>
<script>window.KNOWN_PARTS = <?= json_encode($knownParts, JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}' ?>;</script>
<script src="vendor/tesseract/tesseract.min.js"></script>
<script src="vendor/zxing/zxing-reader.js"></script>
<script src="js/label-scan.js"></script>
<?php page_footer();
