// "Scan labels" page (scan.php): reads Axis labels live from the phone camera.
//
// How it works, in short:
//   1. "Start camera" opens the back camera inside the page (iPhones need a tap first).
//   2. Two readers then run over and over on the part of the picture inside the frame:
//        - text reading (OCR, Tesseract): reads the printed model ("AXIS Q1951-E 7mm 30fps"),
//          "P/N: ..." and "S/N: ...". About once every one to three seconds.
//        - barcode reading (ZXing): reads the square barcode if the label has one.
//          Several times a second. A barcode S/N is exact.
//   3. OCR can misread a letter now and then, so a text value only counts as certain when
//      two readings agree. When both the model and the S/N are certain, the phone beeps,
//      the picture freezes and the result is shown to check.
//   4. "Add unit" saves it in the background (scan.php) and the camera goes on to the next label.
// "Read now" freezes the picture at once and reads it more thoroughly (turned, cropped
// different ways), for labels that the live reading does not manage.
// The reading itself (shared with "Upload SN label photo") is in js/label-reader.js.
(function () {
  'use strict';

  var R = window.LabelReader;
  if (!R || !document.getElementById('live-scan')) return;

  var $ = function (id) { return document.getElementById(id); };
  var intro = $('live-intro'), view = $('live-view'), video = $('live-video'), still = $('live-still');
  var aim = $('live-aim'), hud = $('live-hud'), errorBox = $('live-error');
  var result = $('live-result'), resultStatus = $('live-result-status'), dup = $('live-dup');
  var fModel = $('live-model'), fSerial = $('live-serial'), fPart = $('live-part');
  var known = window.KNOWN_SERIALS || {};   // serial -> model, units already registered
  var addedIds = [];

  var stream = null, track = null;
  var scanning = false;     // the two readers are running
  var ocrBusy = false, codeBusy = false;
  var ocrPass = 0;
  var ev = null;            // what has been read from the current label (see newEvidence)
  var lastSeenKnown = '';   // an already registered S/N shown in the hint

  // ---------- Starting and stopping the camera ----------
  $('live-start').addEventListener('click', start);
  $('live-stop').addEventListener('click', function () { stop(); showIntro(); });
  $('live-capture').addEventListener('click', readNow);
  $('live-again').addEventListener('click', function () { hideResult(); resume(); });
  result.addEventListener('submit', function (e) { e.preventDefault(); addUnit(); });
  fSerial.addEventListener('input', checkDuplicate);

  // The camera is turned off when the page is hidden (other app, screen locked), to save
  // battery. Coming back shows the start button again.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden && stream) { stop(); if (result.hidden) showIntro(); }
  });

  async function start() {
    errorBox.hidden = true;
    unlockSound();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return showError(location.protocol !== 'https:'
        ? 'The camera only works when the page is opened with https:// in the address.'
        : 'This browser cannot use the camera from a web page. On iPhone, use Safari.');
    }
    R.warmUp(); // starts loading the readers while the camera opens
    $('live-start').disabled = true;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: {
        facingMode: { ideal: 'environment' },
        // As sharp as the phone allows: small print needs many pixels.
        width: { ideal: 3840 }, height: { ideal: 2160 }
      } });
    } catch (err) {
      $('live-start').disabled = false;
      return showError(err && err.name === 'NotAllowedError'
        ? 'The camera is blocked for this site. On iPhone: Settings > Apps > Safari > Camera > Allow ' +
          '(or tap "aA" in the address bar > Website Settings > Camera > Allow). Then reload the page.'
        : 'Could not start the camera (' + (err && (err.message || err.name) || err) + ').');
    }
    $('live-start').disabled = false;
    track = stream.getVideoTracks()[0];
    // Continuous focus where the phone lets the page ask for it (iPhones do it by themselves).
    try { await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) { /* not supported */ }
    // Flashlight button only where the phone supports it from a web page (not on iPhone).
    var caps = track.getCapabilities ? track.getCapabilities() : {};
    $('live-torch').hidden = !caps.torch;
    video.srcObject = stream;
    try { await video.play(); } catch (e) { /* autoplay with muted+playsinline works on iPhone */ }
    intro.hidden = true;
    view.hidden = false;
    hideResult();
    resume();
  }

  var torchOn = false;
  $('live-torch').addEventListener('click', async function () {
    torchOn = !torchOn;
    try { await track.applyConstraints({ advanced: [{ torch: torchOn }] }); } catch (e) { torchOn = false; }
    this.classList.toggle('on', torchOn);
  });

  function stop() {
    scanning = false;
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null; track = null; torchOn = false;
    $('live-torch').classList.remove('on');
  }

  function showIntro() { view.hidden = true; intro.hidden = false; hideResult(); }

  function showError(text) { errorBox.textContent = text; errorBox.hidden = false; }

  // ---------- Scanning ----------
  function newEvidence() {
    return {
      code: null,      // from the barcode: { serial, part }
      serials: {},     // S/N read as text -> how many times
      models: {},      // model read as text -> how many times (on an "AXIS ..." line)
      parts: {},       // P/N read as text -> how many times
      knownModel: ''   // model from an already registered P/N
    };
  }

  function resume() {
    if (!stream) return;
    ev = newEvidence();
    ocrPass = 0;
    lastSeenKnown = '';
    video.hidden = false;
    still.hidden = true;
    aim.className = 'live-aim';
    scanning = true;
    say('Point at the label and hold still…');
    loopCode();
    loopOcr();
  }

  function say(text) { hud.textContent = text; }

  // The part of the camera picture inside the frame on screen, as a canvas.
  // (The video is cropped to fill the screen, so the screen position is converted.)
  // While the picture is frozen, the frozen picture is used instead of the camera.
  function frameArea(maxSide) {
    var src = still.hidden ? video : still;
    var vw = src.videoWidth || src.width, vh = src.videoHeight || src.height;
    if (!vw || !vh) return null;
    var box = view.getBoundingClientRect(), a = aim.getBoundingClientRect();
    var scale = Math.max(box.width / vw, box.height / vh);
    var offX = (box.width - vw * scale) / 2, offY = (box.height - vh * scale) / 2;
    // A little extra around the frame, in case the label sticks out a bit.
    var pad = 0.06 * a.width;
    var x = Math.max(0, (a.left - box.left - pad - offX) / scale);
    var y = Math.max(0, (a.top - box.top - pad - offY) / scale);
    var w = Math.min(vw - x, (a.width + 2 * pad) / scale);
    var h = Math.min(vh - y, (a.height + 2 * pad) / scale);
    var k = Math.min(1, maxSide / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.round(w * k); c.height = Math.round(h * k);
    c.getContext('2d').drawImage(src, x, y, w, h, 0, 0, c.width, c.height);
    return c;
  }

  function wholeFrame() {
    if (!still.hidden) return still;
    var c = document.createElement('canvas');
    c.width = video.videoWidth; c.height = video.videoHeight;
    c.getContext('2d').drawImage(video, 0, 0);
    return c;
  }

  // Barcode: several times a second.
  async function loopCode() {
    if (codeBusy) return;
    codeBusy = true;
    while (scanning) {
      var c = frameArea(1400);
      if (c && window.ZXingWASM && !(ev.code && ev.code.serial)) {
        var code = await R.readDataMatrix(c);
        if (!scanning) break;
        if (code.text) {
          var p = R.parseLabel('', code.text);
          if (p.serial) {
            ev.code = { serial: p.serial, part: p.part };
            if (p.model) ev.knownModel = p.model; // P/N already registered
            decide();
          }
        }
      }
      await wait(200);
    }
    codeBusy = false;
  }

  // Text (OCR): one reading at a time, as often as the phone manages.
  async function loopOcr() {
    if (ocrBusy) return;
    ocrBusy = true;
    var worker;
    try {
      say('Loading the text reader… (the first time takes a few seconds)');
      worker = await R.getOcrWorker();
    } catch (e) {
      say('The text reader could not be loaded. Check the internet connection and reload.');
      ocrBusy = false;
      return;
    }
    while (scanning) {
      var c = frameArea(1600);
      if (!c) { await wait(200); continue; }
      var angle = R.tilt(c);
      if (angle) c = R.rotated(c, -angle);
      // Every other reading: Tesseract's "normal page" or "scattered text" layout.
      await R.setOcrMode(worker, ocrPass % 2 ? 11 : 3);
      var text = '';
      try { text = (await worker.recognize(R.blackAndWhite(c))).data.text || ''; } catch (e) { /* try again */ }
      if (!scanning) break;
      ocrPass++;
      // Read without the list of known part numbers, so the model is always taken from
      // the printed "AXIS ..." line. (A misread P/N could otherwise give the wrong model.)
      var parts = window.KNOWN_PARTS;
      window.KNOWN_PARTS = {};
      var p = R.parseLabel(text, '');
      window.KNOWN_PARTS = parts;
      if (p.serial) count(ev.serials, p.serial);
      if (p.model && p.fromAxisLine) count(ev.models, p.model);
      if (p.part) count(ev.parts, p.part);
      decide();
    }
    ocrBusy = false;
  }

  function count(map, value) { map[value] = (map[value] || 0) + 1; }

  // The value read most often, and how often. Among models read equally often, the
  // longest wins (it has the lens and frame rate as well).
  function best(map) {
    var top = '', n = 0;
    Object.keys(map).forEach(function (v) {
      if (map[v] > n || (map[v] === n && v.length > top.length)) { top = v; n = map[v]; }
    });
    return { value: top, n: n };
  }

  // A full model also counts as agreeing with a shorter reading of the same model
  // ("Q1951-E 7mm 30fps" and "Q1951-E 7mm"), since the lens line is sometimes missed.
  function bestModel() {
    var keys = Object.keys(ev.models), top = '', n = 0;
    keys.forEach(function (v) {
      var votes = 0;
      keys.forEach(function (o) { if (v === o || v.indexOf(o + ' ') === 0) votes += ev.models[o]; });
      if (votes > n || (votes === n && v.length > top.length)) { top = v; n = votes; }
    });
    return { value: top, n: n };
  }

  // What we know so far, and how sure we are of it.
  function current() {
    var s = ev.code ? { value: ev.code.serial, n: 99 } : best(ev.serials);
    var m = ev.knownModel ? { value: ev.knownModel, n: 99 } : bestModel();
    var p = ev.code && ev.code.part ? { value: ev.code.part, n: 99 } : best(ev.parts);
    // The P/N read the same way twice, and a unit with that P/N is already registered:
    // use its model if the text has not given a certain model yet.
    var byPart = p.n >= 2 && (window.KNOWN_PARTS || {})[p.value.slice(0, 9)];
    if (byPart && m.n < 2) m = { value: byPart, n: 99, fromPart: true };
    return {
      serial: s.value, serialSure: s.n >= 2, serialFrom: ev.code ? 'barcode' : 'text',
      model: m.value, modelSure: m.n >= 2, modelFrom: ev.knownModel || m.fromPart ? 'part' : 'text',
      part: p.value
    };
  }

  // Called after each reading: done, or keep going (with a hint on screen)?
  function decide() {
    if (!scanning) return;
    var r = current();
    if (r.serial && known[r.serial]) {
      // Already registered: probably the label just added. Do not stop, keep looking.
      if (lastSeenKnown !== r.serial) {
        lastSeenKnown = r.serial;
        say('S/N ' + r.serial + ' is already registered (' + known[r.serial] + '). Point at the next label.');
        aim.className = 'live-aim known';
      }
      ev = newEvidence();
      ocrPass = 0;
      return;
    }
    if (r.serial && r.model && r.serialSure && r.modelSure) {
      return found(r, true);
    }
    // Something read, but not both certain after many tries: show it to check.
    if (r.serial && r.model && ocrPass >= 8) return found(r, false);
    aim.className = 'live-aim' + (r.serial || r.model ? ' half' : '');
    if (r.serial || r.model) {
      say('Reading… ' + (r.model ? 'Model ' + r.model + (r.modelSure ? ' ✓' : '?') : 'model not read yet') + ' · ' +
          (r.serial ? 'S/N ' + r.serial + (r.serialSure ? ' ✓' : '?') : 'S/N not read yet') + '. Hold still.');
    } else if (ocrPass >= 3) {
      say('No label text found yet. Move a little closer or further away, make the text upright, and avoid reflections.');
    }
  }

  // ---------- Result ----------
  function freeze() {
    scanning = false;
    if (!still.hidden) return; // already frozen
    still.width = video.videoWidth; still.height = video.videoHeight;
    still.getContext('2d').drawImage(video, 0, 0);
    video.hidden = true;
    still.hidden = false;
  }

  function found(r, sure) {
    freeze();
    beep(); if (navigator.vibrate) navigator.vibrate(120);
    aim.className = 'live-aim done';
    say(sure ? 'Label read.' : 'Label partly read. Please check.');
    showResult(r, sure);
  }

  function showResult(r, sure) {
    fModel.value = r.model || '';
    fSerial.value = r.serial || '';
    fPart.value = r.part || '';
    var notes = [];
    if (r.serial) notes.push('S/N ' + (r.serialFrom === 'barcode' ? 'from the barcode' : 'read from the text'));
    if (r.model) notes.push('model ' + (r.modelFrom === 'part' ? 'from a unit with the same P/N' : 'read from the text'));
    resultStatus.textContent = (sure ? 'Read OK' : 'Please check the values against the label') +
      (notes.length ? ' (' + notes.join(', ') + ').' : '.');
    resultStatus.className = 'live-result-status ' + (sure ? 'ok' : 'warn');
    result.hidden = false;
    checkDuplicate();
    // Scroll so both the frozen picture and the Add button are in view on a phone.
    result.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }

  function hideResult() { result.hidden = true; dup.hidden = true; }

  function checkDuplicate() {
    var s = fSerial.value.trim().toUpperCase().replace(/\s+/g, '');
    dup.hidden = !known[s];
    if (known[s]) dup.textContent = 'S/N ' + s + ' is already registered (' + known[s] + '). It will not be added again.';
    $('live-add').disabled = !!known[s];
  }

  // "Read now": freeze the picture and read it thoroughly (as for an uploaded photo).
  async function readNow() {
    if (!stream || !video.videoWidth || $('live-capture').disabled) return;
    $('live-capture').disabled = true;
    hideResult();
    var area = frameArea(3000), whole = wholeFrame();
    var sofar = scanning ? current() : null;
    freeze();
    aim.className = 'live-aim busy';
    say('Reading the picture thoroughly… (a few seconds)');
    var code = await R.readDataMatrix(area);
    // Barcode outside the frame? Then look in the whole picture (its position is then
    // not inside the frame area, so it is left out).
    if (!code.text) code = { text: (await R.readDataMatrix(whole)).text };
    var p = R.parseLabel('', code.text);
    if (!p.sure) {
      var text = await R.readText(area, code, function (step) { say('Reading the picture thoroughly… (' + step + ')'); });
      p = R.parseLabel(text, code.text);
    }
    // Keep what the live reading had found, where this reading found nothing.
    var r = {
      serial: p.serial || (sofar && sofar.serial) || '',
      serialFrom: p.serialFromCode ? 'barcode' : 'text',
      model: p.model || (sofar && sofar.model) || '',
      modelFrom: p.model && !p.fromAxisLine ? 'part' : 'text',
      part: p.part || (sofar && sofar.part) || ''
    };
    aim.className = 'live-aim done';
    say(r.serial || r.model ? 'Done. Please check the values.' : 'Could not read the label. Type the values, or tap Scan again.');
    if (r.serial || r.model) beep();
    $('live-capture').disabled = false;
    showResult(r, p.sure);
  }

  // ---------- Adding the unit ----------
  async function addUnit() {
    var btn = $('live-add');
    btn.disabled = true;
    btn.textContent = 'Adding…';
    var body = new URLSearchParams({
      csrf: window.CSRF || '',
      model: fModel.value.trim(),
      serial_number: fSerial.value.trim(),
      part_number: fPart.value.trim()
    });
    var answer;
    try {
      var res = await fetch('scan.php', { method: 'POST', body: body, credentials: 'same-origin' });
      answer = await res.json();
    } catch (e) {
      answer = { ok: false, error: 'Could not reach the server. Check the connection and try again.' };
    }
    btn.textContent = 'Add unit';
    btn.disabled = false;
    if (!answer.ok) {
      resultStatus.textContent = answer.error;
      resultStatus.className = 'live-result-status warn';
      return;
    }
    known[answer.serial] = answer.model;
    addedIds.push(answer.id);
    var li = document.createElement('li');
    li.innerHTML = '<a></a>';
    li.firstChild.href = 'units.php?id=' + answer.id;
    li.firstChild.textContent = answer.model + ' · S/N ' + answer.serial;
    $('live-added-list').prepend(li);
    $('live-added').hidden = false;
    $('live-added-link').href = 'units.php?show=' + addedIds.slice().reverse().join(',');
    hideResult();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    resume();
    say('Added ' + answer.model + ' (S/N ' + answer.serial + '). Point at the next label.');
  }

  // ---------- Sound ----------
  // A short beep when a label is read. iPhones do not vibrate from web pages, so the
  // sound is the signal there. It is prepared on the "Start camera" tap (iPhone rule).
  var audio = null;
  function unlockSound() {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
    } catch (e) { audio = null; }
  }
  function beep() {
    if (!audio) return;
    try {
      var o = audio.createOscillator(), g = audio.createGain();
      o.frequency.value = 1400;
      g.gain.setValueAtTime(0.15, audio.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.15);
      o.connect(g); g.connect(audio.destination);
      o.start(); o.stop(audio.currentTime + 0.15);
    } catch (e) { /* no sound, no problem */ }
  }

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
})();
