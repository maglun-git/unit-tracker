# Plan: scan product labels live with the phone camera

Status: steps 1 to 4 built on 2026-11-11 (iPhone first, text reading as well as barcode). Steps 5 and 6 not started.

## The short version

Build a new page, **Scan labels** (`scan.php`), made for phones. It opens the
phone's back camera inside the web page, you point it at a camera's label, and
the page picks up the serial number and model by itself while you hold the
phone still. You check the result, tap **Add**, and point at the next label.

Everything runs inside the phone's browser, just like the existing
**Upload SN label photo** button. Nothing new is needed on the Inleed web host,
no app store, no paid service and no API keys. Most of the hard work (reading
the barcode and the text on Axis labels) already exists, so this is mainly a
new "front end" on top of the reader we already have.

Rough effort: **about 3 to 4 working sessions** for the main part, plus your
testing on real phones and real labels between the steps.

---

## 1. What already exists (and why it matters)

The Units page has **Upload SN label photo** (`js/label-scan.js`). On a phone
the button already opens the camera, but it takes a *still photo*, then reads it:

1. **The square barcode (DataMatrix)** on the label is read with a library called
   ZXing. On Axis labels it holds the serial number and the part number (P/N),
   for example `B8A44F27ACF5,02150-001-01,...`. A barcode is read exactly, with
   no guessing, and it is fast (a small fraction of a second).
2. **If that P/N is already registered**, the model is copied from that unit,
   so nothing more is needed.
3. **Otherwise the printed text is read** with Tesseract (OCR, "text
   recognition") to find the full model, e.g. `Q1971-E 7mm 8.3fps`. This is the
   slow part: a few seconds per photo, and it tries the photo several ways.
4. The units are saved through the same form handler in `units.php`
   (`save_units_from_photos()`), which also refuses serial numbers that already exist.

Both libraries are stored in `vendor/` on our own host (about 12 MB, kept in the
browser's cache after the first visit).

**What is missing for "live" scanning:** a live camera view in the page, reading
the label continuously instead of from one photo, a layout made for one-handed
phone use, and a quick "next unit" loop.

The key insight for the plan: **the serial number comes from the barcode, and
barcodes can be read many times per second from live video.** Text reading (OCR)
is too slow to run on every video frame, so we only run it once, on one sharp
frame, and only when the model is not already known from the P/N.

---

## 2. The options

### Option A: Live camera in the web page, reading in the phone (recommended)

How it works: the browser has a built-in way to show the camera inside a page
(called `getUserMedia`). The page shows the video, takes a frame several times
a second and hands it to the ZXing barcode reader we already have. As soon as a
barcode is read, the phone vibrates, the frame is frozen, and (only if needed)
OCR reads the model from that one frame.

- Good: works on iPhone (Safari) and Android (Chrome) without installing
  anything. Free. No new server software. No label photos leave the phone.
  Reuses the reader that is already tuned for Axis labels.
- Good: the serial number is exact (barcode), so mistakes are rare.
- Watch out: the page must be opened over **https://** (browsers only allow the
  camera on secure pages). Our site should already have this; we check it in step 0.
- Watch out: OCR on a phone takes a few seconds for a new model. That is fine,
  since it only happens once per new P/N; after that the model is known instantly.
- Watch out: labels are small, so the phone must focus close up. Some phones do
  this better than others. A flashlight button helps in dark storage rooms
  (works on Android; iPhones do not allow it from a web page).

### Option B: Make the site an "app" on the home screen (PWA), as an add-on

A PWA ("progressive web app") is the same website, plus a small description
file and an icon, so it can be added to the phone's home screen and opens full
screen without the browser bars. It can also keep the 12 MB of reading
libraries stored on the phone, so scanning starts faster.

- This is **not an alternative to A**, it is a small extra on top of it.
- Good: feels like an app, one tap to start scanning.
- Watch out: iPhones are a bit stricter with PWAs (for example, the camera
  permission may be asked again more often). We would test that.

### Option C: Send the photo to a cloud text-reading service

The phone takes the picture and sends it (via our PHP) to a service such as
Google Cloud Vision, Microsoft Azure AI Vision, AWS Textract, or an AI model
like Claude that can read images.

- Good: reads difficult text better than Tesseract, and quickly.
- Not so good: needs an account, an API key in `config.php`, and costs a little
  per photo (at our scale, ~30 to 50 units a year, this would be cents, but it
  still needs a card on an account). Label photos leave our own server. Axis IT
  may have opinions about which cloud services are allowed.
- Watch out: our Inleed host is shared hosting, so we cannot install OCR software
  on the server itself; "server OCR" really means "cloud OCR".
- **Recommendation:** keep this as a *backup plan* only. Since the serial number
  comes from the barcode, and the model is usually known from the P/N, OCR
  quality only matters for brand new models. If, after testing, Tesseract keeps
  getting new models wrong, we add this as an optional "Read with cloud" button.

### Option D: A real phone app (iPhone/Android)

- Best camera control, but needs a developer account, app store review,
  a different programming language and updates on every phone. Far too much
  for ~50 units a year. **Not recommended.**

### A note on the browser's own barcode reader

Chrome on Android has a built-in barcode reader (`BarcodeDetector`), but Safari
on iPhone does not. ZXing works everywhere and is already in place, so we keep it.

---

## 3. Recommendation

Go with **Option A**, then add **Option B** (home-screen icon) once A works well.
Keep **Option C** in the drawer unless testing shows a real need.

---

## 4. How it would work for the user

1. On the phone, open the site and tap **Scan labels** (a big button on the
   Units page and in the menu, shown first on phones).
2. The first time, the phone asks "Allow camera?" Tap **Allow**.
3. The camera view fills the screen with a frame to aim at. Tip text:
   "Fill the frame with the label and hold still."
4. When the square barcode is read: short vibration, green frame. The frozen
   picture is shown with the result card:
   - **Serial number** (from the barcode, exact)
   - **Model** (instant if this P/N is known, otherwise "Reading the model…" for
     a few seconds while the text is read)
   - A red warning if this serial number is **already registered**, or was
     already scanned a moment ago.
5. Tap **Add** (the fields can be corrected first), or **Retake**. The camera
   goes live again for the next label.
6. A list at the bottom shows the units added in this round, with a link back
   to the Units page.

Optional "fast mode" switch: when the P/N is known and everything was read with
certainty, the unit is added without the tap, so a box of 10 identical cameras
can be scanned in a minute.

If the barcode cannot be read at all (damaged label), a **Take photo instead**
button falls back to the existing photo reading, and the fields can always be
typed by hand.

---

## 5. Steps to build it

Each step ends with something you can test on your phone. They can be pushed
to `main` one at a time, since nothing existing is removed.

**Step 0: Check the basics (short)**
- Check that the live site opens with `https://` and that `http://` redirects to it.
- Note which phones the sales team and our team use (iPhone/Android, roughly
  which models), so we test on the right ones.

**Step 1: Split the label reader into a shared part (about half a session)**
- Today `js/label-scan.js` does both "handle the photos" and "read a label".
  Move the reading parts (`readDataMatrix`, `readText`, `parseLabel`,
  `cleanSerial` and helpers) into a new file `js/label-reader.js`.
- The photo upload keeps working exactly as before, but now both the photo
  button and the new live scanner use the same reader. That way an improvement
  to label reading helps both.
- Test: upload the same label photos as before and check they give the same results.

**Step 2: The live camera page (about one session)**
- New page `scan.php` (logged-in users only, same menu and style) with a camera
  view and a frame to aim at, built mobile-first.
- New file `js/live-scan.js`: start the back camera at high resolution with
  continuous focus, read the barcode from the frame 5 to 10 times per second,
  stop the camera when the page is hidden (saves battery).
- Clear messages for "camera blocked" (with how to allow it in Safari/Chrome
  settings) and "no camera found" (e.g. on a desktop: link to the photo upload).
- Test: point at labels, see the serial number appear.

**Step 3: Model reading, check and save (about one session)**
- When a barcode is found: freeze the frame, use the P/N lookup (already
  in `units.php` as `KNOWN_PARTS`), otherwise run OCR on that one frame.
- Result card with Add / Retake, duplicate warning (the page gets the list of
  existing serial numbers, which is small), session list.
- Saving reuses the existing `save_units_from_photos()` in `units.php`, sent in
  the background so the camera can stay open. The server still checks for
  duplicates, so nothing is saved twice even if two people scan at once.
- Test: add a few real units, check they appear on the Units page.

**Step 4: Phone polish (about half a session)**
- Vibration (and an optional beep) on a successful read.
- Flashlight button where the phone allows it (Android).
- Zoom slider where the phone allows it (helps with focus on small labels).
- "Fast mode" switch for identical units.
- Big **Scan labels** button on the Units page and the overview on phones.

**Step 5 (optional): Home-screen app / PWA (about half a session)**
- Add a `manifest.json` with the app name and an icon (made from the Axis logo
  you already uploaded), and a small "service worker" that keeps the reading
  libraries stored on the phone.
- You then use "Add to Home Screen" on the phone once.

**Step 6 (only if needed): Cloud OCR backup (about one session plus account setup)**
- Only if testing shows Tesseract often misreads new models. Needs your choice
  of service and an API key placed in `config.php` on the host (never in chat).

**Database:** no changes are needed. The units table already has model,
serial number and part number.

---

## 6. Risks and how we handle them

| Risk | What it means | What we do |
|---|---|---|
| Phone cannot focus close enough | Barcode is blurry, nothing is read | Tip text "move back a little", zoom slider, and the "Take photo instead" fallback (the phone's normal camera app focuses better) |
| Site not on https | Camera does not start at all | Checked in step 0; Inleed offers free certificates (Let's Encrypt) |
| iPhone differences | Safari is stricter about camera, flashlight and PWAs | Test on an iPhone in every step; flashlight button is simply hidden there |
| First start is slow on mobile data | 12 MB of reading libraries to download once | Load the barcode reader (1 MB) first so scanning starts quickly; load OCR in the background; cached afterwards (and stored on the phone with the PWA) |
| OCR misreads a new model | Wrong model text | The result card always shows the model for a quick check before Add; once corrected, that P/N is known and the next unit is instant |
| Warm phone, battery | Camera and reading use power | Stop the camera when the page is hidden or after a minute without a label |
| Non-Axis or unusual labels | No DataMatrix barcode | Falls back to OCR for the serial number too (as today), or typing by hand |
| Breaking the existing photo upload | Step 1 moves code around | Test with the same photos before and after; push step 1 on its own |

---

## 7. What I need from you

- A go-ahead on the recommendation (Option A, then B).
- Which phones to test on (for example your own iPhone or Android).
- A few real units with labels at hand when each step is ready, to test on.

## Ideas for later (not part of this plan)

- Scan a label to **find** a unit, or to start **Send out a unit** / mark it
  returned, without searching the list.
- Scan the asset tag sticker if we add barcoded asset tags.
