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
  // Part numbers already registered, e.g. {"02150-001": "Q1951-E 7mm 30fps"} (set by units.php).
  var knownParts = window.KNOWN_PARTS || {};

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
  // Tesseract's page layout guess: 3 = normal page, 11 = scattered bits of text
  // (better for labels with text in several boxes or columns).
  var ocrMode = null;
  async function setOcrMode(worker, mode) {
    if (ocrMode === mode) return;
    await worker.setParameters({ tessedit_pageseg_mode: String(mode) });
    ocrMode = mode;
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

  // The photo turned by any angle, on a canvas big enough to hold all of it.
  function rotated(canvas, degrees) {
    var c = document.createElement('canvas');
    var rad = degrees * Math.PI / 180, cos = Math.abs(Math.cos(rad)), sin = Math.abs(Math.sin(rad));
    c.width = Math.round(canvas.width * cos + canvas.height * sin);
    c.height = Math.round(canvas.width * sin + canvas.height * cos);
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.translate(c.width / 2, c.height / 2);
    ctx.rotate(degrees * Math.PI / 180);
    ctx.drawImage(canvas, -canvas.width / 2, -canvas.height / 2);
    return c;
  }

  // How many degrees the label is tilted in the photo (between -25 and 25). OCR fails on
  // tilted text, so the photo is turned straight first. Edges (print and label border) are
  // found in a small copy; at the right angle they line up in rows, which gives the
  // sharpest peaks when counted row by row.
  function tilt(canvas) {
    var small = shrunk(canvas, 700), w = small.width, h = small.height;
    var px = small.getContext('2d').getImageData(0, 0, w, h).data;
    var g = new Uint8Array(w * h);
    for (var i = 0; i < w * h; i++) g[i] = (px[i * 4] * 3 + px[i * 4 + 1] * 6 + px[i * 4 + 2]) / 10;
    var xs = [], ys = [];
    for (var y = 0; y < h - 1; y++) {
      for (var x = 0; x < w - 1; x++) {
        var k = y * w + x;
        if (Math.abs(g[k] - g[k + 1]) + Math.abs(g[k] - g[k + w]) > 60) { xs.push(x - w / 2); ys.push(y - h / 2); }
      }
    }
    if (xs.length < 200) return 0;
    var step = Math.max(1, Math.floor(xs.length / 30000));
    var size = Math.ceil(Math.hypot(w, h)) + 2, best = 0, bestScore = -1;
    for (var a = -25; a <= 25; a += 0.5) {
      var rad = a * Math.PI / 180, sin = Math.sin(rad), cos = Math.cos(rad);
      var bins = new Uint32Array(size), score = 0;
      for (var j = 0; j < xs.length; j += step) bins[Math.round(ys[j] * cos - xs[j] * sin + size / 2)]++;
      for (var b = 0; b < size; b++) score += bins[b] * bins[b];
      if (score > bestScore) { bestScore = score; best = a; }
    }
    return Math.abs(best) < 1 ? 0 : best;
  }

  // A black-and-white copy for the OCR. Axis labels are often grey stickers on lighter
  // plastic; one threshold for the whole photo (what Tesseract does by itself) then turns
  // the whole sticker black. Here each pixel is compared with its own surroundings instead:
  // it becomes black only if it is clearly darker than the area around it, which keeps
  // the print and drops grey stickers, shadows and uneven light.
  function blackAndWhite(canvas) {
    var w = canvas.width, h = canvas.height;
    var px = canvas.getContext('2d').getImageData(0, 0, w, h).data;
    var g = new Float32Array(w * h);
    for (var i = 0; i < w * h; i++) g[i] = px[i * 4] * 0.299 + px[i * 4 + 1] * 0.587 + px[i * 4 + 2] * 0.114;
    // Running sums, so the average of any rectangle is quick to get.
    var W = w + 1, sum = new Float64Array(W * (h + 1));
    for (var y = 0; y < h; y++) {
      var row = 0;
      for (var x = 0; x < w; x++) {
        row += g[y * w + x];
        sum[(y + 1) * W + x + 1] = sum[y * W + x + 1] + row;
      }
    }
    var r = Math.max(8, Math.round(Math.max(w, h) / 60));
    var c = document.createElement('canvas');
    c.width = w; c.height = h;
    var ctx = c.getContext('2d'), out = ctx.createImageData(w, h), o = out.data;
    for (var y2 = 0; y2 < h; y2++) {
      var y0 = Math.max(0, y2 - r), y1 = Math.min(h, y2 + r + 1);
      for (var x2 = 0; x2 < w; x2++) {
        var x0 = Math.max(0, x2 - r), x1 = Math.min(w, x2 + r + 1);
        var area = sum[y1 * W + x1] - sum[y0 * W + x1] - sum[y1 * W + x0] + sum[y0 * W + x0];
        var mean = area / ((x1 - x0) * (y1 - y0));
        var k = (y2 * w + x2) * 4;
        o[k] = o[k + 1] = o[k + 2] = g[y2 * w + x2] < mean * 0.8 ? 0 : 255;
        o[k + 3] = 255;
      }
    }
    ctx.putImageData(out, 0, 0);
    return c;
  }

  // The part of the photo around the barcode, turned straight and
  // enlarged so the small print is big enough to read. Labels have their text next to,
  // above or below the barcode, so a generous area around it is kept.
  function labelArea(canvas, code) {
    var p = code.position;
    var cx = (p.topLeft.x + p.topRight.x + p.bottomLeft.x + p.bottomRight.x) / 4;
    var cy = (p.topLeft.y + p.topRight.y + p.bottomLeft.y + p.bottomRight.y) / 4;
    var size = Math.max(10, Math.hypot(p.topRight.x - p.topLeft.x, p.topRight.y - p.topLeft.y));
    // Only the small tilt is undone: the barcode may be printed turned a quarter or half
    // turn compared with the text, so the text may still need turning (tried later).
    var angle = Math.atan2(p.topRight.y - p.topLeft.y, p.topRight.x - p.topLeft.x);
    angle -= Math.round(angle / (Math.PI / 2)) * (Math.PI / 2);
    var scale = Math.min(3, 220 / size); // the barcode becomes about 220 pixels wide
    var w = Math.min(2600, Math.round(size * 11 * scale)), h = Math.min(2200, Math.round(size * 8 * scale));
    var c = document.createElement('canvas');
    c.width = w; c.height = h;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, w, h);
    ctx.translate(w / 2, h / 2);
    ctx.scale(scale, scale);
    ctx.rotate(-angle);
    ctx.translate(-cx, -cy);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(canvas, 0, 0);
    return c;
  }

  // The square barcode. Returns { text, position }, with text '' if there is none or it is unreadable.
  // Very large photos are sometimes harder to read, so a smaller copy is tried as well.
  async function readDataMatrix(canvas) {
    if (!window.ZXingWASM) return { text: '' };
    prepareZxing();
    var tries = [canvas];
    if (Math.max(canvas.width, canvas.height) > 1600) tries.push(shrunk(canvas, 1400));
    for (var i = 0; i < tries.length; i++) {
      try {
        var c = tries[i];
        var data = c.getContext('2d').getImageData(0, 0, c.width, c.height);
        var results = await ZXingWASM.readBarcodes(data, { formats: ['DataMatrix'], tryHarder: true, maxNumberOfSymbols: 1 });
        if (results.length && results[0].isValid) {
          var r = results[0], k = canvas.width / c.width, pos = null;
          if (r.position && r.position.topLeft) {
            pos = {};
            ['topLeft', 'topRight', 'bottomLeft', 'bottomRight'].forEach(function (name) {
              pos[name] = { x: r.position[name].x * k, y: r.position[name].y * k };
            });
          }
          return { text: r.text, position: pos };
        }
      } catch (e) { /* try the next one */ }
    }
    return { text: '' };
  }

  function shrunk(canvas, maxSide) {
    var scale = maxSide / Math.max(canvas.width, canvas.height);
    var c = document.createElement('canvas');
    c.width = Math.round(canvas.width * scale);
    c.height = Math.round(canvas.height * scale);
    c.getContext('2d').drawImage(canvas, 0, 0, c.width, c.height);
    return c;
  }

  // The printed text. Labels differ, so several versions of the photo are read, best
  // guess first, until one gives both a model and a S/N. The texts are returned with
  // the best one first, so parseLabel() takes the model from the clearest reading.
  async function readText(canvas, code, progress) {
    var worker = await getOcrWorker();
    var photo = shrunk(canvas, Math.min(2400, Math.max(canvas.width, canvas.height)));
    var angle = tilt(photo);
    if (angle) photo = rotated(photo, -angle);
    var full = blackAndWhite(photo);
    var area = code.position ? blackAndWhite(labelArea(canvas, code)) : null;
    // [image, quarter turns in degrees, Tesseract layout mode], most likely to work first.
    var tries = [[full, 0, 3], [full, 0, 11]];
    if (area) tries.push([area, 0, 3]);
    tries.push([photo, 0, 11], [full, 90, 3], [full, 270, 3], [full, 180, 3]);
    if (area) tries.push([area, 90, 11], [area, 270, 11]);

    var readings = [];
    for (var i = 0; i < tries.length; i++) {
      progress('try ' + (i + 1) + ' of ' + tries.length);
      var img = tries[i][1] ? rotated(tries[i][0], tries[i][1]) : tries[i][0];
      await setOcrMode(worker, tries[i][2]);
      var text = (await worker.recognize(img)).data.text || '';
      var found = parseLabel(text, code.text);
      // A model on an "AXIS ..." line counts most, then a full S/N, then lens and frame rate.
      var score = (found.fromAxisLine ? 4 : found.model ? 1 : 0)
        + (found.serial.length === 12 ? 2 : 0)
        + (/mm|fps|Mk/.test(found.model) ? 1 : 0);
      readings.push({ text: text, score: score });
      if (score >= 7 || (score >= 6 && found.serialFromCode)) break;
    }
    readings.sort(function (a, b) { return b.score - a.score; });
    return readings.map(function (r) { return r.text; }).join('\n');
  }

  // ---------- Making sense of what was read ----------
  // Exposed as window.parseSnLabel so it can be tested on its own.
  function parseLabel(text, code) {
    var lines = String(text || '').split('\n').map(function (s) {
      return s.trim().toUpperCase().replace(/[‐‑–—]/g, '-');
    }).filter(Boolean);
    var model = '', fromAxisLine = false, fromPart = false;
    var serial = '', serialFromCode = false, part = '';

    // From the barcode: the S/N (12 characters 0-9/A-F) and the part number (e.g. 02150-001-01).
    var parts = String(code || '').toUpperCase().split(/[,;\s\x1d]+/);
    parts.forEach(function (p) {
      p = p.replace(/^(S\/?N|P\/?N)[:=]?/, '');
      if (!serial && /^[0-9A-F]{12}$/.test(p) && /[0-9]/.test(p)) { serial = p; serialFromCode = true; }
      else if (!part && /^\d{5}-\d{3}(-\d{2})?$/.test(p)) part = p;
    });
    // Part number from the text, if the barcode did not have it.
    for (var a = 0; a < lines.length && !part; a++) {
      var pm = lines[a].match(/P\s*[\/|1lI]\s*N\s*[:;.]?\s*([0-9OIL]{5})\s*-\s*([0-9OIL]{3})(?:\s*-\s*([0-9OIL]{2}))?/);
      if (pm) part = digits(pm[1]) + '-' + digits(pm[2]) + (pm[3] ? '-' + digits(pm[3]) : '');
    }
    // A unit with this part number is already registered: use its model, spelled the same way.
    var partKey = part.slice(0, 9);
    if (partKey && knownParts[partKey]) { model = knownParts[partKey]; fromPart = true; }

    // Model: the word after "AXIS", plus lens, frame rate and "Mk II" if they are on the label,
    // e.g. "AXIS Q1971-E 7 mm 8.3 fps" -> "Q1971-E 7mm 8.3fps". Stored without "AXIS",
    // the same way as the units already in the list.
    for (var i = 0; i < lines.length && !model; i++) {
      var m = lines[i].match(/(?:\bAX[I1L|!]?[S5]|\bXIS)\s*([A-Z]{1,2})\s?([0-9OILSZ]{3,4})([A-Z]?)((?:\s?-\s?[A-Z0-9]{1,5})*)/);
      if (m) {
        model = m[1] + digits(m[2]) + m[3] + m[4].replace(/\s/g, '');
        var rest = lines[i].slice(m.index + m[0].length);
        // The lens and frame rate are sometimes on the line below.
        if (!/MM|FPS/.test(rest) && lines[i + 1] && !/[PS]\s*[\/|1lI]\s*N/.test(lines[i + 1])) rest += ' ' + lines[i + 1];
        model += details(rest);
        fromAxisLine = true;
      }
    }
    // No "AXIS" found: take the first thing that looks like a model number.
    for (var j = 0; j < lines.length && !model; j++) {
      if (/^\s*[PS]\s*[\/|]\s*N/.test(lines[j])) continue;
      var m2 = lines[j].match(/\b([A-Z]{1,2}\d{4}[A-Z]?(?:-[A-Z0-9]{1,5})?)\b/);
      if (m2) model = m2[1] + details(lines[j].slice(m2.index + m2[0].length));
    }

    // S/N from the text, if the barcode could not be read: after "S/N:" or "SN:" ...
    for (var n = 0; n < lines.length && !serial; n++) {
      var s = lines[n].match(/\bS\s*[\/|1lI]?\s*N\s*[:;.]?\s*([0-9A-Z| ]{11,16})/);
      if (s) {
        var c = cleanSerial(s[1]);
        if (c.length === 12) serial = c;
      }
    }
    // ... or anywhere, if it starts like an Axis serial number.
    for (var t = 0; t < lines.length && !serial; t++) {
      var words = lines[t].split(/[^0-9A-Z]+/);
      for (var w = 0; w < words.length && !serial; w++) {
        if (words[w].length < 12 || words[w].length > 13) continue;
        var cw = cleanSerial(words[w]);
        if (cw.length === 12 && knownPrefix(cw)) serial = cw;
      }
    }

    return {
      model: model,
      serial: serial,
      part: part,
      fromAxisLine: fromAxisLine,
      serialFromCode: serialFromCode,
      // Only save without asking when the S/N came from the barcode (exact) and the
      // model came from a known part number or was found on the "AXIS ..." line.
      sure: serialFromCode && (fromPart || (fromAxisLine && /^[A-Z]{1,2}\d{3,4}/.test(model)))
    };
  }

  // Lens, frame rate and Mk version from the rest of the model line: " 7mm 8.3fps", " MkII".
  function details(rest) {
    var out = '';
    var mm = rest.match(/\b(\d{1,3}(?:[.,]\d)?)\s?MM\b/);
    if (mm) out += ' ' + mm[1].replace(',', '.') + 'mm';
    var fps = rest.match(/\b(\d{1,3}(?:[.,]\d{1,2})?)\s?FPS\b/);
    if (fps) out += ' ' + fps[1].replace(',', '.') + 'fps';
    // "II" is often read as "IL", "Il" or "11".
    var mk = rest.match(/\bMK\s?(III|IV|[IL1]{2}|2|3)\b/);
    if (mk) out += ' Mk' + (mk[1] === 'III' || mk[1] === '3' ? 'III' : mk[1] === 'IV' ? 'IV' : 'II');
    return out;
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
    return knownPrefix(s) || s;
  }

  // The serial with its start fixed, if it is one B/8 or D/0 mix-up away from a known
  // Axis prefix. Returns '' if it does not start like an Axis serial at all.
  function knownPrefix(s) {
    var start = s.slice(0, 6);
    for (var i = 0; i < AXIS_PREFIXES.length; i++) {
      var p = AXIS_PREFIXES[i], ok = true;
      for (var c = 0; c < 6 && ok; c++) {
        var a = start[c], b = p[c];
        ok = a === b || (a + b).match(/^(B8|8B|D0|0D)$/) !== null;
      }
      if (ok) return p + s.slice(6);
    }
    return '';
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

  window.parseSnLabel = parseLabel;
})();
