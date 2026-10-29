<?php
// Salespeople: the people units are sent to. They do not log in;
// they only receive emails.
//   people.php          -> list
//   people.php?id=new   -> add a person
//   people.php?id=3     -> edit person 3
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $data = [
        trim($_POST['name'] ?? ''),
        trim($_POST['email'] ?? ''),
        trim($_POST['phone'] ?? '') ?: null,
        trim($_POST['region'] ?? '') ?: null,
        trim($_POST['notes'] ?? '') ?: null,
        isset($_POST['active']) ? 1 : 0,
    ];
    if ($data[0] === '' || !filter_var($data[1], FILTER_VALIDATE_EMAIL)) {
        flash('A name and a valid email are required.', 'err');
        redirect('people.php?id=' . urlencode($id));
    }
    try {
        if ($id === 'new') {
            q('INSERT INTO salespeople (name, email, phone, region, notes, active) VALUES (?, ?, ?, ?, ?, ?)', $data);
        } else {
            q('UPDATE salespeople SET name = ?, email = ?, phone = ?, region = ?, notes = ?, active = ? WHERE id = ?', array_merge($data, [(int)$id]));
        }
        flash('Salesperson saved.');
        redirect('people.php');
    } catch (PDOException $e) {
        flash('Could not save: that email is probably already registered.', 'err');
        redirect('people.php?id=' . urlencode($id));
    }
}

if ($id !== null) {
    $p = $id === 'new'
        ? ['name' => '', 'email' => '', 'phone' => '', 'region' => '', 'notes' => '', 'active' => 1]
        : q('SELECT * FROM salespeople WHERE id = ?', [(int)$id])->fetch();
    if (!$p) redirect('people.php');

    // Every Region/location already used by someone. They fill the
    // suggestion list; a new value typed in shows up here next time.
    $regions = q("SELECT DISTINCT region FROM salespeople
                  WHERE region IS NOT NULL AND region <> '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

    page_header($id === 'new' ? 'New salesperson' : 'Edit salesperson', $user);
    ?>
    <form method="post" class="card narrow">
      <?= csrf_field() ?>
      <label>Name <input name="name" value="<?= h($p['name']) ?>" required></label>
      <label>Email <input type="email" name="email" value="<?= h($p['email']) ?>" required></label>
      <label>Phone (optional) <input type="tel" name="phone" value="<?= h($p['phone']) ?>"></label>
      <label>Region/location (optional)
        <input name="region" list="regions" value="<?= h($p['region']) ?>" autocomplete="off" placeholder="Pick from the list or type a new one">
      </label>
      <datalist id="regions">
        <?php foreach ($regions as $r): ?><option value="<?= h($r) ?>"><?php endforeach; ?>
      </datalist>
      <label>Notes (optional) <textarea name="notes" rows="4"><?= h($p['notes']) ?></textarea></label>
      <label class="check"><input type="checkbox" name="active" <?= $p['active'] ? 'checked' : '' ?>> Active (shown when sending out units)</label>
      <button>Save</button> <a href="people.php">Cancel</a>
    </form>
    <?php
    page_footer();
    exit;
}

$people = q('SELECT s.*,
               (SELECT COUNT(*) FROM loans l WHERE l.salesperson_id = s.id AND l.returned_date IS NULL) AS units_out
             FROM salespeople s ORDER BY s.active DESC, s.name')->fetchAll();

page_header('Salespeople', $user);
?>
<p><a class="button" href="people.php?id=new">+ Add salesperson</a> <a class="button secondary" href="people_import.php">Import from CSV</a></p>
<table>
  <tr><th>Name</th><th>Email</th><th>Phone</th><th>Region/location</th><th>Units out</th><th></th></tr>
  <?php foreach ($people as $p): ?>
  <tr class="<?= $p['active'] ? '' : 'muted' ?>">
    <td><?= h($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?></td>
    <td><?= h($p['email']) ?></td>
    <td><?= h($p['phone']) ?></td>
    <td><?= h($p['region']) ?><?= $p['notes'] ? ' <span class="muted" title="' . h($p['notes']) . '">(notes)</span>' : '' ?></td>
    <td><a href="loans.php?person=<?= $p['id'] ?>"><?= (int)$p['units_out'] ?></a></td>
    <td><a href="people.php?id=<?= $p['id'] ?>">Edit</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if (!$people) echo '<p class="muted">No salespeople yet.</p>';
page_footer();
