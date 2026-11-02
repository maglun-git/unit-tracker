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
    <label for="body">Text</label>
    <div class="wysiwyg" id="wysiwyg" hidden>
      <div class="wy-toolbar" role="toolbar" aria-label="Formatting">
        <select data-block title="Paragraph style">
          <option value="p">Normal text</option>
          <option value="h2">Heading</option>
          <option value="h3">Small heading</option>
        </select>
        <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
        <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
        <button type="button" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
        <label class="wy-color" title="Text colour"><span>A</span><input type="color" data-color value="#c0392b"></label>
        <span class="wy-sep"></span>
        <button type="button" data-cmd="insertUnorderedList" title="Bulleted list">&bull;&ndash;</button>
        <button type="button" data-cmd="insertOrderedList" title="Numbered list">1.&ndash;</button>
        <span class="wy-sep"></span>
        <button type="button" data-link title="Make the selected text a link">Link</button>
        <button type="button" data-cmd="unlink" title="Remove link">Unlink</button>
        <button type="button" data-cmd="removeFormat" title="Remove bold, colour etc. from the selected text">Clear</button>
        <button type="button" data-source class="wy-source" title="Show or edit the HTML code">&lt;/&gt; HTML</button>
      </div>
      <div class="wy-area" id="wy-area" contenteditable="true" spellcheck="true"></div>
    </div>
    <textarea name="body" id="body" rows="20" class="code"><?= h($form['body']) ?></textarea>
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
    <h2>Tips</h2>
    <p class="muted">Write the email like in a normal word processor. Enter starts a new paragraph,
      Shift+Enter a new line. Pasted text comes in without formatting, so the email stays clean.
      Click <b>&lt;/&gt; HTML</b> to see or change the code itself, for example
      <code>&lt;p style="color:#c0392b"&gt;</code>; many email programs ignore <code>&lt;style&gt;</code> blocks.</p>
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
// A small WYSIWYG ("what you see is what you get") editor for the email text.
// The real form field is still the <textarea name="body">: the editor shows the
// same HTML as formatted text, and copies its HTML back into the textarea
// before anything is sent. Without JavaScript the plain textarea is used.
(function () {
  var form = document.getElementById('template-form');
  var textarea = document.getElementById('body');
  var subject = document.getElementById('subject');
  var box = document.getElementById('wysiwyg');
  var area = document.getElementById('wy-area');
  var sourceBtn = box.querySelector('[data-source]');
  var blockSelect = box.querySelector('[data-block]');
  var sourceMode = false;   // true while the HTML code is shown
  var savedRange = null;    // where the cursor was in the editor
  var lastField = area;     // the field a placeholder click goes into

  function exec(cmd, value) {
    area.focus();
    restoreRange();
    document.execCommand(cmd, false, value === undefined ? null : value);
    saveRange();
    sync();
  }
  // Copy the editor's HTML into the real form field.
  function sync() {
    if (!sourceMode) textarea.value = area.innerHTML;
  }
  function saveRange() {
    var sel = window.getSelection();
    if (sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer)) {
      savedRange = sel.getRangeAt(0).cloneRange();
    }
  }
  function restoreRange() {
    if (!savedRange) return;
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }

  // Start: show the editor, hide the code box.
  area.innerHTML = textarea.value;
  box.appendChild(textarea);   // the code box sits inside the editor frame
  box.hidden = false;
  textarea.hidden = true;
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}

  area.addEventListener('input', sync);
  ['keyup', 'mouseup', 'focus'].forEach(function (ev) {
    area.addEventListener(ev, function () { saveRange(); lastField = area; });
  });
  document.addEventListener('selectionchange', function () {
    if (document.activeElement === area) saveRange();
  });
  subject.addEventListener('focus', function () { lastField = subject; });
  textarea.addEventListener('focus', function () { lastField = textarea; });

  // Pasted text comes in as plain text, so no hidden Word/web styles end up in the email.
  area.addEventListener('paste', function (e) {
    var text = (e.clipboardData || window.clipboardData).getData('text/plain');
    if (!text) return;
    e.preventDefault();
    document.execCommand('insertText', false, text);
  });

  // Toolbar buttons. mousedown + preventDefault keeps the text selected.
  box.querySelectorAll('[data-cmd]').forEach(function (btn) {
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
    btn.addEventListener('click', function () { exec(this.getAttribute('data-cmd')); });
  });
  blockSelect.addEventListener('change', function () {
    exec('formatBlock', '<' + this.value + '>');
  });
  box.querySelector('[data-color]').addEventListener('input', function () {
    // Colours as style="color:…" on the text, which email programs understand.
    document.execCommand('styleWithCSS', false, true);
    exec('foreColor', this.value);
    document.execCommand('styleWithCSS', false, false);
  });
  var linkBtn = box.querySelector('[data-link]');
  linkBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
  linkBtn.addEventListener('click', function () {
    restoreRange();
    if (!savedRange || savedRange.collapsed) {
      alert('First select the text that should become a link.');
      return;
    }
    var url = prompt('Link address (for example https://… or {{site_url}}):', 'https://');
    if (url && url !== 'https://') exec('createLink', url);
  });

  // Show the paragraph style of the text where the cursor is.
  document.addEventListener('selectionchange', function () {
    if (document.activeElement !== area) return;
    var block = (document.queryCommandValue('formatBlock') || 'p').toLowerCase();
    blockSelect.value = (block === 'h2' || block === 'h3') ? block : 'p';
  });

  // Switch between the formatted view and the HTML code.
  sourceBtn.addEventListener('click', function () {
    if (sourceMode) {
      area.innerHTML = textarea.value;
      textarea.hidden = true;
      area.hidden = false;
      lastField = area;
      area.focus();
    } else {
      sync();
      textarea.hidden = false;
      area.hidden = true;
      lastField = textarea;
      textarea.focus();
    }
    sourceMode = !sourceMode;
    sourceBtn.classList.toggle('active', sourceMode);
    box.querySelectorAll('.wy-toolbar > :not([data-source])').forEach(function (el) {
      el.disabled = sourceMode;
      el.classList.toggle('off', sourceMode);
    });
  });

  form.addEventListener('submit', sync);

  // Clicking a placeholder puts it where the cursor is (subject, editor or HTML code).
  document.querySelectorAll('[data-insert]').forEach(function (link) {
    link.addEventListener('mousedown', function (e) { e.preventDefault(); });
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var text = this.getAttribute('data-insert');
      if (lastField === area && !sourceMode) {
        area.focus();
        if (savedRange) restoreRange();
        else { var r = document.createRange(); r.selectNodeContents(area); r.collapse(false); savedRange = r; restoreRange(); }
        document.execCommand('insertText', false, text);
        saveRange();
        sync();
        return;
      }
      var f = lastField === area ? textarea : lastField;
      var start = f.selectionStart, end = f.selectionEnd;
      f.value = f.value.slice(0, start) + text + f.value.slice(end);
      f.focus();
      f.selectionStart = f.selectionEnd = start + text.length;
    });
  });
})();
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
<table class="email-list">
  <tr><th>Email</th><th>When it is sent</th><th>Subject</th></tr>
  <?php foreach ($templates as $c => $default):
      $t = get_template($c); ?>
  <tr>
    <td><a href="emails.php?code=<?= h($c) ?>"><b><?= h($t['name']) ?></b></a></td>
    <td><small><?= h($t['about']) ?></small></td>
    <td><?= h($t['subject']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php page_footer();
