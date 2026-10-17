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
        trim($_POST['region'] ?? '') ?: null,
        isset($_POST['active']) ? 1 : 0,
    ];
    if ($data[0] === '' || !filter_var($data[1], FILTER_VALIDATE_EMAIL)) {
        flash('A name and a valid email are required.', 'err');
        redirect('people.php?id=' . urlencode($id));
    }
    try {
        if ($id === 'new') {
            q('INSERT INTO salespeople (name, email, region, active) VALUES (?, ?, ?, ?)', $data);
        } else {
            q('UPDATE salespeople SET name = ?, email = ?, region = ?, active = ? WHERE id = ?', array_merge($data, [(int)$id]));
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
        ? ['name' => '', 'email' => '', 'region' => '', 'active' => 1]
        : q('SELECT * FROM salespeople WHERE id = ?', [(int)$id])->fetch();
    if (!$p) redirect('people.php');

    page_header($id === 'new' ? 'New salesperson' : 'Edit salesperson', $user);
    ?>
    <form method="post" class="card narrow">
      <?= csrf_field() ?>
      <label>Name <input name="name" value="<?= h($p['name']) ?>" required></label>
      <label>Email <input type="email" name="email" value="<?= h($p['email']) ?>" required></label>
      <label>Region / team (optional) <input name="region" value="<?= h($p['region']) ?>"></label>
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
<p><a class="button" href="people.php?id=new">+ Add salesperson</a></p>
<table>
  <tr><th>Name</th><th>Email</th><th>Region</th><th>Units out</th><th></th></tr>
  <?php foreach ($people as $p): ?>
  <tr class="<?= $p['active'] ? '' : 'muted' ?>">
    <td><?= h($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?></td>
    <td><?= h($p['email']) ?></td>
    <td><?= h($p['region']) ?></td>
    <td><a href="loans.php?person=<?= $p['id'] ?>"><?= (int)$p['units_out'] ?></a></td>
    <td><a href="people.php?id=<?= $p['id'] ?>">Edit</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if (!$people) echo '<p class="muted">No salespeople yet.</p>';
page_footer();
