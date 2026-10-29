<?php
// The E-mail page: admins edit the subject and HTML text of every
// email the tracker sends. Templates live in includes/templates.php
// (the standard texts) and the email_templates table (edited versions).
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

$templates = default_templates();
$code = $_GET['code'] ?? '';
if ($code !== '' && !isset($templates[$code])) redirect('emails.php');

$preview = null;   // [subject, html] to show under the form
$form = null;      // unsaved text from the form (after Preview / Send test)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code !== '') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $subject = trim(str_replace(["\r", "\n"], ' ', $_POST['subject'] ?? ''));
    $body = str_replace("\r\n", "\n", $_POST['body'] ?? '');

    if ($action === 'reset') {
        // Back to the standard text: remove the edited version.
        try {
            q('DELETE FROM email_templates WHERE code = ?', [$code]);
        } catch (PDOException $e) {
            // Table not made yet: nothing to remove.
        }
        flash('"' . $templates[$code]['name'] . '" now uses the standard text again.');
        redirect('emails.php?code=' . urlencode($code));
    }

    if ($subject === '' || trim($body) === '') {
        flash('The subject and the text cannot be empty.', 'err');
        $form = ['subject' => $subject, 'body' => $body];
    } elseif ($action === 'save') {
        q('REPLACE INTO email_templates (code, subject, body, updated_by) VALUES (?, ?, ?, ?)',
          [$code, $subject, $body, $user['name']]);
        flash('Saved. From now on "' . $templates[$code]['name'] . '" is sent with this text.');
        redirect('emails.php?code=' . urlencode($code));
    } elseif ($action === 'preview' || $action === 'test') {
        // Show (or send) the text in the form without saving it.
        $form = ['subject' => $subject, 'body' => $body];
        $preview = fill_placeholders($subject, $body, sample_values($code));
        if ($action === 'test') {
            $debug = null;
            $ok = send_email($user['email'], '[TEST] ' . $preview[0], $preview[1], [], $debug, true);
            // Shown at the top of this page (we stay here, so the text in the form is kept).
            flash($ok ? "Test sent to {$user['email']}, with example values. Not saved yet: click Save to keep the text."
                      : 'The test could not be sent: ' . mail_error(), $ok ? 'ok' : 'err');
        }
    }
}

// ---------- One template: the editor ----------
if ($code !== '') {
    $t = get_template($code);
    $form = $form ?? ['subject' => $t['subject'], 'body' => $t['body']];
    $preview = $preview ?? fill_placeholders($form['subject'], $form['body'], sample_values($code));
    $unknown = unknown_placeholders($code, $form['subject'] . ' ' . $form['body']);
    $vars = $t['vars'] + common_placeholders();

    page_header('E-mail: ' . $t['name'], $user);
    ?>
<p><a href="emails.php">&larr; All emails</a></p>
<p class="muted"><?= h($t['about']) ?><br>
<?= $t['edited']
    ? 'Last saved ' . h(date('j M Y H:i', strtotime($t['updated_at']))) . ($t['updated_by'] ? ' by ' . h($t['updated_by']) : '') . '.'
    : 'Uses the standard text.' ?></p>

<?php if ($unknown): ?>
<div class="flash err">These placeholders are not known and will not be filled in:
  <?= h(implode(', ', array_map(fn($p) => '{{' . $p . '}}', $unknown))) ?>. Check the spelling against the list.</div>
<?php endif; ?>

<div class="editor">
  <form method="post" class="card" id="template-form">
    <?= csrf_field() ?>
    <label>Subject <input name="subject" id="subject" value="<?= h($form['subject']) ?>" required></label>
    <label>Text (HTML) <textarea name="body" id="body" rows="20" class="code" required><?= h($form['body']) ?></textarea></label>
    <button name="action" value="save">Save</button>
    <button name="action" value="preview" class="secondary">Preview</button>
    <button name="action" value="test" class="secondary">Send me a test</button>
  </form>

  <div class="card">
    <h2>Placeholders</h2>
    <p class="muted">Click one to put it where the cursor is. It is replaced with the real value when the email is sent.</p>
    <table class="placeholders">
      <?php foreach ($vars as $name => $about): ?>
      <tr><td><a href="#" data-insert="{{<?= h($name) ?>}}"><code>{{<?= h($name) ?>}}</code></a></td><td><?= h($about) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <h2>HTML tips</h2>
    <p class="muted"><code>&lt;p&gt;…&lt;/p&gt;</code> paragraph,
      <code>&lt;br&gt;</code> new line, <code>&lt;b&gt;…&lt;/b&gt;</code> bold,
      <code>&lt;a href="…"&gt;…&lt;/a&gt;</code> link.
      Write styles on the tag, like <code>&lt;p style="color:#c0392b"&gt;</code>; many email programs ignore <code>&lt;style&gt;</code> blocks.</p>
  </div>
</div>

<h2>Preview <small class="muted">(with example values<?= $form['body'] !== $t['body'] || $form['subject'] !== $t['subject'] ? ', not saved yet' : '' ?>)</small></h2>
<div class="card">
  <p><b>Subject:</b> <?= h($preview[0]) ?></p>
  <iframe class="email-preview" sandbox title="Email preview" srcdoc="<?= h(html_email_page($preview[1])) ?>"></iframe>
</div>

<?php if ($t['edited']): ?>
<form method="post" class="inline" onsubmit="return confirm('Throw away your changes and go back to the standard text?')">
  <?= csrf_field() ?>
  <button name="action" value="reset" class="danger">Go back to the standard text</button>
</form>
<?php endif; ?>

<script>
// Clicking a placeholder puts it into the subject or text, at the cursor.
var lastField = document.getElementById('body');
['subject', 'body'].forEach(function (id) {
  document.getElementById(id).addEventListener('focus', function () { lastField = this; });
});
document.querySelectorAll('[data-insert]').forEach(function (link) {
  link.addEventListener('click', function (e) {
    e.preventDefault();
    var f = lastField, text = this.getAttribute('data-insert');
    var start = f.selectionStart, end = f.selectionEnd;
    f.value = f.value.slice(0, start) + text + f.value.slice(end);
    f.focus();
    f.selectionStart = f.selectionEnd = start + text.length;
  });
});
</script>
<?php
    page_footer();
    exit;
}

// ---------- All templates: the list ----------
page_header('E-mail', $user);
?>
<p>These are all the emails the tracker sends. Click one to change its subject or text.
Changes are used from the next email that is sent.</p>
<table>
  <tr><th>Email</th><th>When it is sent</th><th>Subject</th><th>Text</th></tr>
  <?php foreach ($templates as $c => $default):
      $t = get_template($c); ?>
  <tr>
    <td><a href="emails.php?code=<?= h($c) ?>"><b><?= h($t['name']) ?></b></a></td>
    <td><small><?= h($t['about']) ?></small></td>
    <td><?= h($t['subject']) ?></td>
    <td><?= $t['edited']
        ? '<span class="badge orange">Edited</span><br><small>' . h(date('j M Y', strtotime($t['updated_at']))) . '</small>'
        : '<span class="badge grey">Standard</span>' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php page_footer();
