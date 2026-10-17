<?php
// All loans with a simple filter (out / overdue / returned / all).
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$filters = ['out' => 'Out now', 'overdue' => 'Overdue', 'returned' => 'Returned', 'all' => 'All'];
$show = array_key_exists($_GET['show'] ?? '', $filters) ? $_GET['show'] : 'out';

$where = ['1 = 1'];
$params = [];
if ($show === 'out')      $where[] = 'l.returned_date IS NULL';
if ($show === 'overdue')  { $where[] = 'l.returned_date IS NULL AND l.due_date < ?'; $params[] = today(); }
if ($show === 'returned') $where[] = 'l.returned_date IS NOT NULL';
if (!empty($_GET['person'])) { $where[] = 'l.salesperson_id = ?'; $params[] = (int)$_GET['person']; }

$loans = q('SELECT l.*, u.model, u.serial_number, s.name AS person_name
            FROM loans l
            JOIN units u ON u.id = l.unit_id
            JOIN salespeople s ON s.id = l.salesperson_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.returned_date IS NOT NULL, l.due_date, l.id DESC', $params)->fetchAll();

page_header('Loans', $user);
?>
<p><a class="button" href="loan_edit.php">+ Send out a unit</a></p>
<p class="tabs">
  <?php foreach ($filters as $k => $label): ?>
    <a href="loans.php?show=<?= $k ?><?= !empty($_GET['person']) ? '&person=' . (int)$_GET['person'] : '' ?>" class="<?= $k === $show ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</p>
<table>
  <tr><th>Status</th><th>Unit</th><th>Holder</th><th>Purpose / customer</th><th>Out</th><th>Due</th><th>Returned</th><th></th></tr>
  <?php foreach ($loans as $l): ?>
  <tr>
    <td><?= loan_badge($l) ?></td>
    <td><?= h($l['model']) ?><br><small><?= h($l['serial_number']) ?></small></td>
    <td><?= h($l['person_name']) ?></td>
    <td><?= h($l['purpose']) ?><?= $l['customer'] ? ' · ' . h($l['customer']) : '' ?></td>
    <td><?= h($l['start_date']) ?></td>
    <td><?= h($l['due_date']) ?></td>
    <td><?= h($l['returned_date']) ?></td>
    <td><a href="loan_edit.php?id=<?= $l['id'] ?>">Open</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if (!$loans) echo '<p class="muted">No loans match this filter.</p>';
page_footer();
