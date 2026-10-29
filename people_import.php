<?php
// Import salespeople from a CSV file (for example saved from Excel).
// Columns: Name, e-mail, phone, Region/location
//   - A header row is optional. If there is one, the columns can come in any order.
//   - Works with ; (Swedish Excel) and , as separator.
//   - Works with UTF-8 (with or without BOM) and older Excel files (Windows-1252).
//   - Someone whose email is already in the tracker is skipped or updated,
//     depending on the choice on the form.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

// Reads the CSV text into a list of rows: [name, email, phone, region].
function parse_people_csv(string $text): array
{
    // Remove the invisible BOM that Excel puts at the start of UTF-8 files.
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        $text = substr($text, 3);
    }
    // Not valid UTF-8? Then it is an older Excel export: convert so å, ä, ö survive.
    if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Guess the separator from the first line: whichever of ; , or tab appears most.
    $first = strtok($text, "\n");
    $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $sep = key($counts);

    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    while (($cells = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        $cells = array_map('trim', $cells);
        if (implode('', $cells) === '') continue; // empty line
        $rows[] = $cells;
    }
    fclose($fh);
    if (!$rows) return [];

    // Which column is which. Default order: Name, e-mail, phone, Region/location.
    $map = ['name' => 0, 'email' => 1, 'phone' => 2, 'region' => 3];
    $first_line = 1; // the line number in the file of the first person
    // Is the first row a header? It is if no cell in it looks like an email address.
    if (!preg_grep('/@/', $rows[0])) {
        $header = array_shift($rows);
        $first_line = 2;
        $found = [];
        foreach ($header as $i => $title) {
            $t = strtolower(preg_replace('/[^a-z]/i', '', $title));
            if (preg_match('/^(name|namn|fullname)/', $t))                     $found['name'] = $i;
            elseif (preg_match('/^(email|mail|epost)/', $t))                   $found['email'] = $i;
            elseif (preg_match('/^(phone|tel|mobil|mobile|cell)/', $t))        $found['phone'] = $i;
            elseif (preg_match('/^(region|location|team|ort|plats)/', $t))     $found['region'] = $i;
        }
        // Only trust the header if it at least names the email column.
        if (isset($found['email'])) $map = $found;
    }

    $out = [];
    foreach ($rows as $n => $cells) {
        $get = fn($k) => isset($map[$k], $cells[$map[$k]]) ? $cells[$map[$k]] : '';
        $out[] = ['line' => $n + $first_line, 'name' => $get('name'), 'email' => $get('email'),
                  'phone' => $get('phone'), 'region' => $get('region')];
    }
    return $out;
}

$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $file = $_FILES['csv'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        flash('Please choose a CSV file to upload.', 'err');
        redirect('people_import.php');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        flash('The file is too big (max 2 MB).', 'err');
        redirect('people_import.php');
    }
    $update = ($_POST['duplicates'] ?? 'skip') === 'update';
    $rows = parse_people_csv(file_get_contents($file['tmp_name']));

    $summary = ['added' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
    $seen = [];
    foreach ($rows as $r) {
        $email = $r['email'];
        $label = $r['name'] !== '' ? "{$r['name']} <{$r['email']}>" : $r['email'];
        if ($r['name'] === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $summary['errors'][] = 'Line ' . $r['line'] . ': needs a name and a valid email ("' . $r['name'] . '", "' . $r['email'] . '")';
            continue;
        }
        if (isset($seen[strtolower($email)])) {
            $summary['skipped'][] = "$label (listed twice in the file)";
            continue;
        }
        $seen[strtolower($email)] = true;

        $existing = q('SELECT id FROM salespeople WHERE email = ?', [$email])->fetch();
        if (!$existing) {
            q('INSERT INTO salespeople (name, email, phone, region, active) VALUES (?, ?, ?, ?, 1)',
              [$r['name'], $email, $r['phone'] ?: null, $r['region'] ?: null]);
            $summary['added'][] = $label;
        } elseif ($update) {
            // Empty cells in the file do not wipe what is already saved.
            q('UPDATE salespeople SET name = ?, phone = COALESCE(?, phone), region = COALESCE(?, region) WHERE id = ?',
              [$r['name'], $r['phone'] ?: null, $r['region'] ?: null, $existing['id']]);
            $summary['updated'][] = $label;
        } else {
            $summary['skipped'][] = "$label (already in the tracker)";
        }
    }
    if (!$rows) $summary['errors'][] = 'The file had no rows to import.';
}

page_header('Import salespeople', $user);

if ($summary) {
    $titles = ['added' => 'Added', 'updated' => 'Updated', 'skipped' => 'Skipped', 'errors' => 'Could not import'];
    echo '<div class="card"><h2>Result</h2><p>';
    $parts = [];
    foreach ($titles as $k => $t) $parts[] = '<b>' . count($summary[$k]) . '</b> ' . strtolower($t);
    echo implode(' · ', $parts) . '</p>';
    foreach ($titles as $k => $t) {
        if (!$summary[$k]) continue;
        echo '<h3>' . h($t) . '</h3><ul>';
        foreach ($summary[$k] as $line) echo '<li>' . h($line) . '</li>';
        echo '</ul>';
    }
    echo '<p><a href="people.php">Back to Salespeople</a></p></div>';
}
?>
<form method="post" enctype="multipart/form-data" class="card narrow">
  <?= csrf_field() ?>
  <p>Upload a CSV file with the columns <b>Name, e-mail, phone, Region/location</b>.
     In Excel: <i>File → Save As → CSV UTF-8</i>. A header row is fine, and both
     <code>;</code> and <code>,</code> work as separator.</p>
  <pre class="sample">Name;e-mail;phone;Region/location
Anna Svensson;anna.svensson@axis.com;+46 70 123 45 67;Nordics</pre>
  <label>CSV file <input type="file" name="csv" accept=".csv,.txt,text/csv" required></label>
  <p><b>If the email is already in the tracker:</b></p>
  <label class="check"><input type="radio" name="duplicates" value="skip" checked> Skip that row (keep what is saved)</label>
  <label class="check"><input type="radio" name="duplicates" value="update"> Update name, phone and Region/location from the file</label>
  <button>Import</button> <a href="people.php">Cancel</a>
</form>
<?php
page_footer();
