// "Upload SN label photo" on the Units page.
//
// Everything happens in the browser, so nothing extra is needed on the web host:
//   1. The square barcode on the label (a DataMatrix) is read with ZXing.
//      On Axis labels it starts with the serial number, e.g. "B8A44F27ACF5,02150-001-01,...".
//   2. The printed text is read with Tesseract (OCR) to find the model ("AXIS Q1951-E ...").
//      If the barcode could not be read, the S/N is taken from the text instead.
//   3. If both were found with certainty, the unit is saved straight away.
//      Otherwise the form is shown so the user can check or fix the values first.
// The libraries live in vendor/ (copied from npm), so no outside website is used.
(function () {
  'use strict';

  var input = document.getElementById('label-photo');
  if (!input) return;

  var panel = document.getElementById('scan-panel');
  var form = document.getElementById('scan-form');
  var statusBox = document.getElementById('scan-status');
  var preview = document.getElementById('scan-preview');
  var modelField = form.elements.model;
  var serialField = form.elements.serial_number;
  var fields = document.getElementById('scan-fields');
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

  // Start loading as soon as the button is pressed, while the camera is open.
  document.querySelector('label[for="label-photo"]').addEventListener('click', function () {
    prepareZxing();
    getOcrWorker();
  });

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (file) scan(file);
  });

  document.getElementById('scan-cancel').addEventListener('click', function () {
    panel.hidden = true;
    input.value = '';
  });

  function say(text, kind) {
    statusBox.textContent = text;
    statusBox.className = 'scan-status' + (kind ? ' ' + kind : '');
  }

  // ---------- The main flow ----------
  async function scan(file) {
    panel.hidden = false;
    fields.hidden = true;
    modelField.value = '';
    serialField.value = '';
    preview.src = URL.createObjectURL(file);
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    say('Reading the label… (the first time takes a few extra seconds)', 'busy');

    try {
      var canvas = await photoToCanvas(file, 2400);
      var code = await readDataMatrix(canvas);
      var text = await readText(canvas);
      var found = parseLabel(text, code);

      modelField.value = found.model;
      serialField.value = found.serial;

      if (found.sure) {
        say('Found ' + found.model + ', S/N ' + found.serial + '. Saving…', 'ok');
        form.submit();
        return;
      }
      fields.hidden = false;
      if (!found.model && !found.serial) {
        say('Could not read the label. Type the model and S/N, or try a sharper photo taken straight on.', 'warn');
      } else {
        say('Please check the values below (the photo was not fully clear), then press Save.', 'warn');
      }
      (found.model ? serialField : modelField).focus();
    } catch (err) {
      fields.hidden = false;
      say('Could not read the photo (' + (err && err.message ? err.message : err) + '). Type the values instead.', 'warn');
    }
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
