// "Upload SN label photo" on the Units page.
//
// Photos can be chosen with the button (several at once) or dragged onto the page.
// Everything happens in the browser, so nothing extra is needed on the web host:
//   1. The square barcode on the label (a DataMatrix) is read with ZXing.
//      On Axis labels it starts with the serial number, e.g. "B8A44F27ACF5,02150-001-01,...".
//      The barcode also holds the part number (P/N). If a unit with the same P/N is already
//      registered, its model is used, so the text does not even have to be read.
//   2. The printed text is read with Tesseract (OCR) to find the full model
//      ("AXIS Q1971-E 7 mm 8.3 fps" -> "Q1971-E 7mm 8.3fps"). Labels look different
//      from model to model, so the area around the barcode, the whole photo and the
//      photo turned sideways are tried until the label makes sense.
//      If the barcode could not be read, the S/N is taken from the text instead.
//   3. Each photo becomes one row (one unit). Click a photo to see it large. If every row was read with certainty,
//      all units are saved straight away. Otherwise the rows are shown so the user
//      can check or fix them, then press "Save all".
// The reading itself is in js/label-reader.js (shared with the live "Scan labels" page).
(function () {
  'use strict';

  var input = document.getElementById('label-photo');
  if (!input) return;

  var panel = document.getElementById('scan-panel');
  var form = document.getElementById('scan-form');
  var statusBox = document.getElementById('scan-status');
  var list = document.getElementById('scan-items');
  var buttons = document.getElementById('scan-buttons');
  var overlay = document.getElementById('drop-overlay');
  // The shared label reader (js/label-reader.js).
  var R = window.LabelReader;
  var warmUp = R.warmUp, photoToCanvas = R.photoToCanvas, readDataMatrix = R.readDataMatrix,
      readText = R.readText, parseLabel = R.parseLabel;

  // Start loading as soon as the button is pressed, while the camera or file picker is open.
  document.querySelector('label[for="label-photo"]').addEventListener('click', warmUp);

  input.addEventListener('change', function () {
    addFiles(input.files);
    input.value = ''; // so the same photo can be chosen again later
  });

  // ---------- Drag and drop: photos dropped anywhere on the page ----------
  var dragDepth = 0;
  function hasFiles(e) {
    return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1;
  }
  document.addEventListener('dragenter', function (e) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth++;
    overlay.hidden = false;
    warmUp();
  });
  document.addEventListener('dragover', function (e) {
    if (!hasFiles(e)) return;
    e.preventDefault(); // needed, otherwise the browser just opens the photo
    e.dataTransfer.dropEffect = 'copy';
  });
  document.addEventListener('dragleave', function (e) {
    if (!hasFiles(e)) return;
    dragDepth = Math.max(0, dragDepth - 1);
    if (!dragDepth) overlay.hidden = true;
  });
  document.addEventListener('drop', function (e) {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth = 0;
    overlay.hidden = true;
    addFiles(e.dataTransfer.files);
  });

  document.getElementById('scan-cancel').addEventListener('click', function () {
    queue = [];
    list.innerHTML = '';
    panel.hidden = true;
  });

  function say(text, kind) {
    statusBox.textContent = text;
    statusBox.className = 'scan-status' + (kind ? ' ' + kind : '');
  }

  // ---------- One row per photo ----------
  var queue = [];      // rows still waiting to be read
  var busy = false;

  function addFiles(fileList) {
    var files = Array.prototype.filter.call(fileList || [], function (f) {
      return /^image\//.test(f.type) || /\.(jpe?g|png|heic|heif|webp|gif|bmp)$/i.test(f.name);
    });
    if (!files.length) {
      if (fileList && fileList.length) alert('Only photos (JPG, PNG and so on) can be used.');
      return;
    }
    panel.hidden = false;
    buttons.hidden = true;
    files.forEach(function (file) { queue.push(makeRow(file)); });
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (!busy) work();
  }

  function makeRow(file) {
    var row = document.createElement('div');
    row.className = 'scan-item busy';
    row.innerHTML =
      '<img alt="Label photo" title="Click to enlarge" tabindex="0">' +
      '<div class="scan-item-fields">' +
      '  <p class="scan-item-status">Waiting…</p>' +
      '  <label>Model <input name="model[]" list="known-models" placeholder="e.g. Q1951-E" autocapitalize="characters"></label>' +
      '  <label>Serial number (S/N) <input name="serial_number[]" placeholder="e.g. B8A44F27ACF5" autocapitalize="characters" autocomplete="off" spellcheck="false"></label>' +
      '  <input type="hidden" name="part_number[]">' +
      '</div>' +
      '<button type="button" class="link scan-remove" title="Do not add this one">Remove</button>';
    var img = row.querySelector('img');
    img.src = URL.createObjectURL(file);
    img.addEventListener('click', function () { openPhoto(img.src); });
    img.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPhoto(img.src); }
    });
    row.querySelector('.scan-remove').addEventListener('click', function () {
      queue = queue.filter(function (r) { return r !== row; });
      row.remove();
      if (!list.children.length) panel.hidden = true;
      else if (!busy) finish();
    });
    row.file = file;
    list.appendChild(row);
    return row;
  }

  async function work() {
    busy = true;
    while (queue.length) {
      var row = queue.shift();
      var total = list.children.length;
      var number = Array.prototype.indexOf.call(list.children, row) + 1;
      say('Reading photo ' + number + ' of ' + total + '… (the first time takes a few extra seconds)', 'busy');
      await readRow(row);
    }
    busy = false;
    finish();
  }

  async function readRow(row) {
    var status = row.querySelector('.scan-item-status');
    var model = row.querySelector('[name="model[]"]');
    var serial = row.querySelector('[name="serial_number[]"]');
    var part = row.querySelector('[name="part_number[]"]');
    status.textContent = 'Reading…';
    try {
      var canvas = await photoToCanvas(row.file, 3000);
      var code = await readDataMatrix(canvas);
      var found = parseLabel('', code.text);
      // Same part number as a unit we already have: take its model and skip the slow text reading.
      if (!found.sure) {
        var text = await readText(canvas, code, function (step) { status.textContent = 'Reading… (' + step + ')'; });
        found = parseLabel(text, code.text);
      }
      model.value = found.model;
      serial.value = found.serial;
      part.value = found.part;
      row.readSure = found.sure;
      status.textContent = found.sure ? 'Read OK'
        : (!found.model && !found.serial ? 'Could not read the label. Click the photo to see it large, then type the values or remove the row.'
        : 'Please check: the photo was not fully clear. Click it to see it large.');
    } catch (err) {
      row.readSure = false;
      status.textContent = 'Could not read the photo (' + (err && err.message ? err.message : err) + ').';
    }
    row.classList.remove('busy');
    row.classList.add(row.readSure ? 'ok' : 'warn');
  }

  // All photos read: save straight away if everything is certain, otherwise let the user check.
  function finish() {
    var rows = Array.prototype.slice.call(list.children);
    if (!rows.length) return;
    // The same serial twice in this batch is suspicious, so those rows need a look.
    var seen = {};
    rows.forEach(function (r) { r.sure = !!r.readSure; });
    rows.forEach(function (r) {
      var s = r.querySelector('[name="serial_number[]"]').value.toUpperCase();
      if (s && seen[s]) {
        [r, seen[s]].forEach(function (x) {
          x.sure = false; x.classList.remove('ok'); x.classList.add('warn');
          x.querySelector('.scan-item-status').textContent = 'Same S/N as another photo. Remove one of them.';
        });
      }
      seen[s] = r;
    });
    rows.forEach(function (r) {
      r.querySelectorAll('input').forEach(function (i) { i.required = true; });
    });
    var unsure = rows.filter(function (r) { return !r.sure; }).length;
    if (!unsure) {
      say(rows.length === 1 ? 'Label read. Saving…' : 'All ' + rows.length + ' labels read. Saving…', 'ok');
      form.submit();
      return;
    }
    buttons.hidden = false;
    document.getElementById('scan-save').textContent = rows.length === 1 ? 'Save' : 'Save all ' + rows.length;
    say(unsure === 1 && rows.length === 1
      ? 'Please check the values below, then press Save.'
      : unsure + ' of ' + rows.length + ' photos need a check (marked in orange). Fix or remove them, then press Save all.', 'warn');
    var first = list.querySelector('.scan-item.warn input');
    if (first) first.focus();
  }

  // ---------- Seeing a photo large ----------
  // Click the photo to see it as large as the screen allows; click again to zoom in
  // to full size where you clicked, and scroll around. Esc, × or the dark area closes it.
  var viewer = null;
  function openPhoto(src) {
    if (!viewer) {
      viewer = document.createElement('div');
      viewer.className = 'photo-viewer';
      viewer.hidden = true;
      viewer.innerHTML = '<img alt="Label photo, large"><button type="button" class="photo-viewer-close" title="Close (Esc)">×</button>' +
        '<p class="photo-viewer-hint">Click the photo to zoom in. Esc closes.</p>';
      document.body.appendChild(viewer);
      var big = viewer.querySelector('img');
      viewer.addEventListener('click', function (e) {
        if (e.target !== big) { closePhoto(); return; }
        var zoom = !viewer.classList.contains('zoomed');
        // Where in the photo was clicked, so that spot stays in view after zooming in.
        var r = big.getBoundingClientRect();
        var fx = (e.clientX - r.left) / r.width, fy = (e.clientY - r.top) / r.height;
        viewer.classList.toggle('zoomed', zoom);
        if (zoom) {
          viewer.scrollLeft = fx * big.offsetWidth - viewer.clientWidth / 2;
          viewer.scrollTop = fy * big.offsetHeight - viewer.clientHeight / 2;
        }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !viewer.hidden) closePhoto();
      });
    }
    viewer.classList.remove('zoomed');
    viewer.querySelector('img').src = src;
    viewer.hidden = false;
    document.body.classList.add('no-scroll');
  }
  function closePhoto() {
    viewer.hidden = true;
    document.body.classList.remove('no-scroll');
  }

})();
