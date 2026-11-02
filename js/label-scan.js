// "Upload SN label photo" on the Units page.
//
// Photos can be chosen with the button (several at once) or dragged onto the page.
// Everything happens in the browser, so nothing extra is needed on the web host:
//   1. The square barcode on the label (a DataMatrix) is read with ZXing.
//      On Axis labels it starts with the serial number, e.g. "B8A44F27ACF5,02150-001-01,...".
//   2. The printed text is read with Tesseract (OCR) to find the model ("AXIS Q1951-E ...").
//      If the barcode could not be read, the S/N is taken from the text instead.
//   3. Each photo becomes one row (one unit). If every row was read with certainty,
//      all units are saved straight away. Otherwise the rows are shown so the user
//      can check or fix them, then press "Save all".
// The libraries live in vendor/ (copied from npm), so no outside website is used.
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
  var vendor = new URL('vendor/', location.href).href;

  // Known start of Axis serial numbers (the serial is the camera's network MAC address).
  // Used to fix the most common OCR mix-up, B and 8, at the start of the serial.
  var AXIS_PREFIXES = ['00408C', 'ACCC8E', 'B8A44F', 'E82725'];

  // ---------- Loading the libraries (only once) ----------
  var ocrWorker = null;
  function getOcrWorker() {
    if (!ocrWorker) {
      ocrWorker = Tesseract.createWorker('eng', 1, {
        workerPath: vendor + 'tesseract/worker.min.js',
        corePath: vendor + 'tesseract/core/',
        langPath: vendor + 'tesseract/lang',
        gzip: true
      });
      ocrWorker.catch(function () { ocrWorker = null; });
    }
    return ocrWorker;
  }
  var zxingReady = false;
  function prepareZxing() {
    if (!zxingReady && window.ZXingWASM) {
      ZXingWASM.prepareZXingModule({
        overrides: { locateFile: function (path) { return vendor + 'zxing/' + path; } },
        fireImmediately: true
      });
      zxingReady = true;
    }
  }
  function warmUp() { prepareZxing(); getOcrWorker(); }

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
      '<img alt="Label photo">' +
      '<div class="scan-item-fields">' +
      '  <p class="scan-item-status">Waiting…</p>' +
      '  <label>Model <input name="model[]" list="known-models" placeholder="e.g. Q1951-E" autocapitalize="characters"></label>' +
      '  <label>Serial number (S/N) <input name="serial_number[]" placeholder="e.g. B8A44F27ACF5" autocapitalize="characters" autocomplete="off" spellcheck="false"></label>' +
      '</div>' +
      '<button type="button" class="link scan-remove" title="Do not add this one">Remove</button>';
    row.querySelector('img').src = URL.createObjectURL(file);
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
    status.textContent = 'Reading…';
    try {
      var canvas = await photoToCanvas(row.file, 2400);
      var code = await readDataMatrix(canvas);
      var text = await readText(canvas);
      var found = parseLabel(text, code);
      model.value = found.model;
      serial.value = found.serial;
      row.readSure = found.sure;
      status.textContent = found.sure ? 'Read OK'
        : (!found.model && !found.serial ? 'Could not read the label. Type the values or remove the row.'
        : 'Please check: the photo was not fully clear.');
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

  // Draw the photo onto a canvas, made smaller if needed (phone photos are huge).
  // The browser turns the photo the right way up using the camera's orientation info.
  function photoToCanvas(file, maxSide) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * scale);
        c.height = Math.round(img.naturalHeight * scale);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        resolve(c);
      };
      img.onerror = function () { reject(new Error('not an image the browser can open')); };
      img.src = URL.createObjectURL(file);
    });
  }

  function rotated(canvas, degrees) {
    var c = document.createElement('canvas');
    var quarter = degrees % 180 !== 0;
    c.width = quarter ? canvas.height : canvas.width;
    c.height = quarter ? canvas.width : canvas.height;
    var ctx = c.getContext('2d');
    ctx.translate(c.width / 2, c.height / 2);
    ctx.rotate(degrees * Math.PI / 180);
    ctx.drawImage(canvas, -canvas.width / 2, -canvas.height / 2);
    return c;
  }

  // The square barcode. Returns its text, or '' if there is none or it is unreadable.
  async function readDataMatrix(canvas) {
    if (!window.ZXingWASM) return '';
    prepareZxing();
    try {
      var data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
      var results = await ZXingWASM.readBarcodes(data, { formats: ['DataMatrix'], tryHarder: true, maxNumberOfSymbols: 1 });
      return results.length && results[0].isValid ? results[0].text : '';
    } catch (e) {
      return '';
    }
  }

  // The printed text. If nothing label-like is found, the photo may be sideways,
  // so it is turned a quarter at a time and read again.
  async function readText(canvas) {
    var worker = await getOcrWorker();
    var best = '', bestScore = -1;
    var turns = [0, 90, 270, 180];
    for (var i = 0; i < turns.length; i++) {
      var img = turns[i] ? rotated(canvas, turns[i]) : canvas;
      var result = await worker.recognize(img);
      var text = result.data.text || '';
      var found = parseLabel(text, '');
      // 2 points for a model on an "AXIS ..." line, 1 for a 12 character S/N.
      var score = (found.model && /AX.?S\s/i.test(text) ? 2 : 0) + (found.serial.length === 12 ? 1 : 0);
      if (score > bestScore) { best = text; bestScore = score; }
      if (score === 3) break;
    }
    return best;
  }

  // ---------- Making sense of what was read ----------
  // Exposed as window.parseSnLabel so it can be tested on its own.
  function parseLabel(text, code) {
    var lines = String(text || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    var model = '', modelFromAxisLine = false, serial = '', serialFromCode = false;

    // Model: the word after "AXIS" on the first line, e.g. "AXIS Q1951-E 7mm 30 fps" -> "Q1951-E".
    // Stored without "AXIS", the same way as the units already in the list.
    for (var i = 0; i < lines.length && !model; i++) {
      var line = lines[i].toUpperCase().replace(/[‐‑–—]/g, '-');
      var m = line.match(/\bAX[I1L|!]S\s+([A-Z]{1,2})\s?([0-9OILSZ]{3,4})([A-Z]?)((?:\s?-\s?[A-Z0-9]{1,5})*)/);
      if (m) {
        model = m[1] + digits(m[2]) + m[3] + m[4].replace(/\s/g, '');
        var mk = line.match(/\bMK\s?(II|III|IV|2|3)\b/);
        if (mk) model += ' Mk' + mk[1].replace('2', 'II').replace('3', 'III');
        modelFromAxisLine = true;
      }
    }
    // No "AXIS" found: take the first thing that looks like a model number.
    for (var j = 0; j < lines.length && !model; j++) {
      if (/^\s*[PS]\s*[\/|]\s*N/i.test(lines[j])) continue;
      var m2 = lines[j].toUpperCase().match(/\b([A-Z]{1,2}\d{4}[A-Z]?(?:-[A-Z0-9]{1,5})?)\b/);
      if (m2) model = m2[1];
    }

    // S/N from the barcode: the first part, if it is 12 hex characters.
    var parts = String(code || '').toUpperCase().split(/[,;\s]+/);
    for (var k = 0; k < parts.length && !serial; k++) {
      if (/^[0-9A-F]{12}$/.test(parts[k])) { serial = parts[k]; serialFromCode = true; }
    }
    // Otherwise from the text after "S/N:".
    if (!serial) {
      for (var n = 0; n < lines.length && !serial; n++) {
        var s = lines[n].match(/S\s*[\/|1lI]\s*N\s*[:;.]?\s*([0-9A-Za-z| ]{11,16})/);
        if (s) serial = cleanSerial(s[1]);
      }
    }

    return {
      model: model,
      serial: serial,
      // Only save without asking when the S/N came from the barcode (exact)
      // and the model was found on the "AXIS ..." line.
      sure: serialFromCode && modelFromAxisLine && /^[A-Z]{1,2}\d{3,4}/.test(model)
    };
  }

  // Letters that OCR often reads instead of digits.
  function digits(s) {
    return s.replace(/O/g, '0').replace(/[IL]/g, '1').replace(/S/g, '5').replace(/Z/g, '2');
  }

  function cleanSerial(raw) {
    var s = raw.toUpperCase().replace(/[\s|]/g, '')
      .replace(/O/g, '0').replace(/[IL]/g, '1').replace(/S/g, '5').replace(/Z/g, '2')
      .replace(/G/g, '6').replace(/Q/g, '0').replace(/T/g, '7');
    s = s.replace(/[^0-9A-F]/g, '').slice(0, 12);
    if (s.length !== 12) return s;
    // Fix the start if it is one B/8 or D/0 mix-up away from a known Axis prefix.
    var start = s.slice(0, 6);
    for (var i = 0; i < AXIS_PREFIXES.length; i++) {
      var p = AXIS_PREFIXES[i], ok = true;
      for (var c = 0; c < 6 && ok; c++) {
        var a = start[c], b = p[c];
        ok = a === b || (a + b).match(/^(B8|8B|D0|0D)$/) !== null;
      }
      if (ok) return p + s.slice(6);
    }
    return s;
  }

  window.parseSnLabel = parseLabel;
})();
