<?php
// Overview: what is out right now, sorted by due date.
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$out = q('SELECT l.*, u.model, u.serial_number, s.name AS person_name
          FROM loans l
          JOIN units u ON u.id = l.unit_id
          JOIN salespeople s ON s.id = l.salesperson_id
          WHERE l.returned_date IS NULL
          ORDER BY l.due_date')->fetchAll();

$overdue = count(array_filter($out, fn($l) => days_until($l['due_date']) < 0));
$soon = count(array_filter($out, fn($l) => ($d = days_until($l['due_date'])) >= 0 && $d <= 7));
$available = q("SELECT COUNT(*) FROM units u WHERE u.status = 'available'
                AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.unit_id = u.id AND l.returned_date IS NULL)")->fetchColumn();

page_header('Overview', $user);
?>
<div class="stats">
  <div class="stat"><b><?= count($out) ?></b>units out</div>
  <div class="stat red"><b><?= $overdue ?></b>overdue</div>
  <div class="stat orange"><b><?= $soon ?></b>due within 7 days</div>
  <div class="stat green"><b><?= (int)$available ?></b>available in stock</div>
</div>
<p><a class="button" href="loan_edit.php">+ Send out a unit</a></p>

<h2>Units out right now</h2>
<?php if (!$out): ?>
  <p class="muted">No units are out at the moment.</p>
<?php else: ?>
<table>
  <tr><th>Status</th><th>Unit</th><th>Holder</th><th>Purpose / customer</th><th>Due</th><th></th></tr>
  <?php foreach ($out as $l): ?>
  <tr>
    <td><?= loan_badge($l) ?></td>
    <td><?= h($l['model']) ?><br><small><?= h($l['serial_number']) ?></small></td>
    <td><?= h($l['person_name']) ?></td>
    <td><?= h($l['purpose']) ?><?= $l['customer'] ? ' · ' . h($l['customer']) : '' ?></td>
    <td><?= h($l['due_date']) ?></td>
    <td><a href="loan_edit.php?id=<?= $l['id'] ?>">Open</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif;
page_footer();
