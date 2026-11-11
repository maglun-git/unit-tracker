<?php
// "Scan labels": add units by pointing the phone camera at the label.
// Made for phones (iPhone first). The page shows the live camera; js/live-scan.js
// reads the label while you hold the phone still:
//   - the printed text (OCR): the model, e.g. "AXIS Q1951-E 7mm 30fps", and "S/N: ..."
//   - the square barcode, when there is one (exact S/N and P/N)
// You check the result and press "Add unit". The unit is saved in the background
// (below), so the camera can stay open for the next label.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

// ---- Adding one unit (sent by js/live-scan.js, answered with JSON) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        echo json_encode(['ok' => false, 'error' => 'The page has expired. Reload it and try again.']);
        exit;
    }
    $model = trim((string)($_POST['model'] ?? ''));
    // Serials on Axis labels are upper case, without spaces.
    $serial = strtoupper(preg_replace('/\s+/', '', (string)($_POST['serial_number'] ?? '')));
    $part = strtoupper(trim((string)($_POST['part_number'] ?? ''))) ?: null;
    if ($model === '' || $serial === '') {
        echo json_encode(['ok' => false, 'error' => 'Both a model and a serial number are needed.']);
        exit;
    }
    // The same serial number may only be registered once.
    $same = q('SELECT id, model FROM units WHERE serial_number = ?', [$serial])->fetch();
    if ($same) {
        echo json_encode(['ok' => false, 'error' => "S/N $serial is already registered ({$same['model']}).", 'id' => (int)$same['id']]);
        exit;
    }
    try {
        q('INSERT INTO units (model, serial_number, part_number, status) VALUES (?, ?, ?, ?)', [$model, $serial, $part, 'available']);
        echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId(), 'model' => $model, 'serial' => $serial]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => "S/N $serial could not be saved."]);
    }
    exit;
}

// ---- The page ----
// What the reader needs to know: models already used (suggestions while typing),
// part numbers already registered (P/N -> model) and serial numbers already
// registered (to warn before adding a unit twice).
$knownParts = [];
foreach (q('SELECT part_number, model FROM units WHERE part_number IS NOT NULL ORDER BY id DESC')->fetchAll() as $p) {
    $knownParts[substr($p['part_number'], 0, 9)] ??= $p['model'];
}
$known = [];
foreach (q('SELECT serial_number, model FROM units')->fetchAll() as $u) {
    $known[strtoupper($u['serial_number'])] = $u['model'];
}
$models = q('SELECT DISTINCT model FROM units ORDER BY model')->fetchAll(PDO::FETCH_COLUMN);

page_header('Scan labels', $user);
?>
<div class="live-scan" id="live-scan">
  <!-- Before the camera starts: a short explanation and the start button.
       (iPhones only show the camera after a tap on the page.) -->
  <div class="card live-intro" id="live-intro">
    <p>Point the phone's camera at the label on a camera. The page reads the
       <b>model</b> and the <b>serial number (S/N)</b> from the printed text, and
       from the square barcode when there is one. You check the result and press
       <b>Add unit</b>.</p>
    <p class="muted">Tip: hold the phone so the text on the label is the right way up, about
       10 to 15 cm away, and let the label fill the frame.</p>
    <p><button type="button" id="live-start" class="live-big">Start camera</button></p>
    <p id="live-error" class="flash err" hidden></p>
    <p class="muted">No camera, or reading does not work? <a href="units.php">Upload a label photo on the Units page</a> instead.</p>
  </div>

  <!-- The live camera. The frame shows where to put the label. -->
  <div class="live-view" id="live-view" hidden>
    <video id="live-video" playsinline muted autoplay></video>
    <canvas id="live-still" hidden></canvas>
    <div class="live-aim" id="live-aim"></div>
    <div class="live-hud" id="live-hud" role="status">Starting the camera…</div>
    <div class="live-tools">
      <button type="button" class="live-tool" id="live-torch" hidden title="Flashlight">Light</button>
      <button type="button" class="live-tool" id="live-capture" title="Read this picture more thoroughly">Read now</button>
      <button type="button" class="live-tool" id="live-stop" title="Stop the camera">Stop</button>
    </div>
  </div>

  <!-- What was read: check it, then add the unit. -->
  <form class="card live-result" id="live-result" hidden autocomplete="off">
    <p class="live-result-status" id="live-result-status"></p>
    <label>Model <input name="model" id="live-model" list="known-models" placeholder="e.g. Q1951-E 7mm 30fps" autocapitalize="characters" required></label>
    <label>Serial number (S/N) <input name="serial_number" id="live-serial" placeholder="e.g. B8A44F27ACF5" autocapitalize="characters" spellcheck="false" required></label>
    <label>Part number, P/N (optional) <input name="part_number" id="live-part" placeholder="e.g. 02150-001-01" autocapitalize="characters" spellcheck="false"></label>
    <p class="live-warn" id="live-dup" hidden></p>
    <p class="live-buttons">
      <button type="submit" id="live-add" class="live-big">Add unit</button>
      <button type="button" class="secondary live-big" id="live-again">Scan again</button>
    </p>
  </form>

  <!-- Units added during this visit. -->
  <div class="card live-added" id="live-added" hidden>
    <h2>Added now</h2>
    <ul id="live-added-list"></ul>
    <p><a href="units.php" id="live-added-link">See them on the Units page</a></p>
  </div>
</div>
<datalist id="known-models">
  <?php foreach ($models as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?>
</datalist>
<script>
window.KNOWN_PARTS = <?= json_encode($knownParts, JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}' ?>;
window.KNOWN_SERIALS = <?= json_encode((object)$known, JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}' ?>;
window.CSRF = <?= json_encode(trim(preg_replace('/.*value="([^"]*)".*/', '$1', csrf_field()))) ?>;
</script>
<script defer src="vendor/tesseract/tesseract.min.js?v=<?= filemtime(__DIR__ . '/vendor/tesseract/tesseract.min.js') ?>"></script>
<script defer src="vendor/zxing/zxing-reader.js?v=<?= filemtime(__DIR__ . '/vendor/zxing/zxing-reader.js') ?>"></script>
<script defer src="js/label-reader.js?v=<?= filemtime(__DIR__ . '/js/label-reader.js') ?>"></script>
<script defer src="js/live-scan.js?v=<?= filemtime(__DIR__ . '/js/live-scan.js') ?>"></script>
<?php page_footer();
