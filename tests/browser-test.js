#!/usr/bin/env node
/**
 * Headless browser check of index.html against the PHP backend and tests/mock-anthropic.php.
 * tests/smoke.sh runs it when Playwright is installed. To run it by hand, start the servers as
 * smoke.sh does, then:
 *
 *   APP_URL=http://127.0.0.1:8791/ MOCK_STATE_DIR=/path/to/mock-state node tests/browser-test.js [--shots docs/screenshots]
 *
 * --shots also saves the README screenshots (desktop and phone).
 */
'use strict';
const { chromium, devices } = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');

const APP = process.env.APP_URL || 'http://127.0.0.1:8791/';
const STATE = process.env.MOCK_STATE_DIR || '';
const shotsArg = process.argv.indexOf('--shots');
const SHOTS = shotsArg > 0 ? process.argv[shotsArg + 1] : '';
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'listing-browser-'));
// Google Fonts load through the HTTPS proxy when one is set; the app itself is local.
const proxy = process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY, bypass: '127.0.0.1,localhost' } : undefined;

let failures = 0;
function check(ok, label) {
  console.log((ok ? '  PASS ' : '  FAIL ') + label);
  if (!ok) failures++;
}

// A product "photo" for the test: a green mug, 2400x1800 so the page has to shrink it.
const MUG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 900" width="2400" height="1800">
<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f7f1e8"/><stop offset="1" stop-color="#eadfce"/></linearGradient>
<linearGradient id="m" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#1d7556"/><stop offset=".42" stop-color="#43b088"/><stop offset="1" stop-color="#17614a"/></linearGradient>
<linearGradient id="w" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#cfa173"/><stop offset="1" stop-color="#9c7047"/></linearGradient></defs>
<rect width="1200" height="900" fill="url(#bg)"/><rect y="640" width="1200" height="260" fill="#e4d4bf"/>
<ellipse cx="600" cy="712" rx="340" ry="62" fill="url(#w)"/><ellipse cx="600" cy="700" rx="306" ry="46" fill="#bf8f61"/>
<ellipse cx="600" cy="684" rx="200" ry="24" fill="#000" opacity=".16"/>
<path d="M772 410q104 0 104 92t-104 92" fill="none" stroke="#258a64" stroke-width="38" stroke-linecap="round"/>
<path d="M428 330h344v262q0 86-86 86H514q-86 0-86-86z" fill="url(#m)"/>
<ellipse cx="600" cy="330" rx="172" ry="30" fill="#33a07a"/><ellipse cx="600" cy="333" rx="152" ry="21" fill="#5b3a22"/>
<rect x="468" y="372" width="26" height="236" rx="13" fill="#fff" opacity=".33"/>
<g fill="none" stroke="#fff" stroke-width="11" stroke-linecap="round" opacity=".75"><path d="M548 280q-22-38 0-76t0-76"/><path d="M604 270q-22-38 0-76t0-76"/><path d="M660 280q-22-38 0-76t0-76"/></g>
</svg>`;

function trackRequests(page) {
  const s = { posts: 0, inFlight: 0, max: 0 };
  const done = (r) => { if (r.url().includes('api/generate.php')) s.inFlight--; };
  page.on('request', (r) => {
    if (r.url().includes('api/generate.php')) { s.posts++; s.inFlight++; s.max = Math.max(s.max, s.inFlight); }
  });
  page.on('requestfinished', done);
  page.on('requestfailed', done);
  return s;
}

function collectErrors(page) {
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  return errors;
}

// Waits until a product has no request running.
async function waitIdle(page, id, timeout = 30000) {
  await page.waitForFunction((pid) => {
    const p = document.getElementById(pid);
    return p && !p.querySelector('[data-stop]');
  }, id, { timeout });
}

async function uploadAndGenerate(page, photo, notes) {
  const before = await page.locator('.plate:not(#ex)').count();
  await page.setInputFiles('#file', photo);
  const plate = page.locator('.plate:not(#ex)').nth(before);
  await plate.waitFor();
  const id = await plate.getAttribute('id');
  await plate.locator('textarea.notes').fill(notes);
  await plate.locator('[data-gen]').click();
  return { plate: page.locator('#' + id), id };
}

// Clean screenshots for the README, taken in fresh pages after the checks.
async function screenshots(browser, photo) {
  fs.mkdirSync(SHOTS, { recursive: true });
  const english = () => localStorage.setItem('listing-writer', JSON.stringify({ lang: 'en' }));

  const desk = await browser.newContext({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 2 });
  await desk.addInitScript(english);
  const page = await desk.newPage();
  await page.goto(APP);
  await page.waitForSelector('#dot.on');
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: path.join(SHOTS, 'desktop.png') });
  const { plate, id } = await uploadAndGenerate(page, photo, 'Green ceramic mug');
  await waitIdle(page, id);
  await plate.locator('[data-tab="en"]').click();
  await page.evaluate(() => { document.activeElement.blur(); document.getElementById('msg').textContent = ''; });
  await plate.screenshot({ path: path.join(SHOTS, 'listing.png') });
  await desk.close();

  const phone = await browser.newContext({ ...devices['iPhone 13'] });
  await phone.addInitScript(english);
  const mp = await phone.newPage();
  await mp.goto(APP);
  await mp.waitForSelector('#dot.on');
  const r = await uploadAndGenerate(mp, photo, 'Green ceramic mug');
  await waitIdle(mp, r.id);
  await r.plate.locator('[data-tab="en"]').click();
  await mp.evaluate((pid) => {
    document.activeElement.blur();
    document.getElementById('msg').textContent = '';
    const tabs = document.querySelector('#' + pid + ' .ltabs');
    window.scrollTo(0, tabs.getBoundingClientRect().top + window.scrollY - 300);
  }, r.id);
  await mp.screenshot({ path: path.join(SHOTS, 'phone.png') });
  await phone.close();
}

(async () => {
  const browser = await chromium.launch({ proxy });
  try {
    const photo = path.join(TMP, 'mug.jpg');
    {
      const p = await browser.newPage({ viewport: { width: 2400, height: 1800 } });
      await p.setContent('<body style="margin:0">' + MUG + '</body>');
      await p.screenshot({ path: photo, type: 'jpeg', quality: 90 });
      await p.close();
    }

    console.log('Desktop, Arabic interface');
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(APP).origin });
    const page = await ctx.newPage();
    const errors = collectErrors(page);
    const req = trackRequests(page);

    await page.goto(APP);
    await page.waitForSelector('#dot.on', { timeout: 10000 });
    check((await page.textContent('#aiTxt')) === 'المساعد جاهز', 'status dot is green after the health check');
    check((await page.getAttribute('html', 'dir')) === 'rtl', 'page starts in Arabic, right to left');
    check((await page.locator('#ex [data-tab]').count()) === 3, 'example product shows its three language tabs');
    check(await page.isVisible('#dlCsv'), 'Download CSV button is always visible');
    check((await page.textContent('.sig')).trim() === 'Built by Awsan Khaled', 'footer line is shown');

    const { plate, id } = await uploadAndGenerate(page, photo, 'كوب قهوة أخضر');
    await waitIdle(page, id);
    check(req.posts === 3, 'one request per language: ' + req.posts);
    check(req.max <= 3, 'at most 3 requests at a time: ' + req.max);
    const last = STATE ? JSON.parse(fs.readFileSync(path.join(STATE, 'last-request.json'), 'utf8')) : null;
    if (last) {
      check(Math.max(last.image.width, last.image.height) === 1568 && last.image.mime === 'image/jpeg',
        `photo resized to 1568 px JPEG before upload (${last.image.width}x${last.image.height}, ${last.image.bytes} bytes)`);
      check(last.model === 'claude-sonnet-5-5' && last.ui === 'ar', 'best quality uses the configured model; "seen" asked in Arabic');
    }
    check((await plate.locator('.seen i').count()) >= 3, '"what the assistant sees" chips are shown');
    for (const lang of ['ar', 'en', 'tr']) {
      await plate.locator(`[data-tab="${lang}"]`).click();
      const title = plate.locator('textarea[data-k="title"]');
      const text = await title.inputValue();
      check(text.length > 10 && (await title.getAttribute('dir')) === (lang === 'ar' ? 'rtl' : 'ltr'), `${lang} tab is filled: ${text.slice(0, 45)}`);
      const tags = (await plate.locator('textarea[data-k="hashtags"]').inputValue()).split(/\s+/);
      check(tags.length === 5 && tags.every((h) => h.startsWith('#')), `${lang} hashtags all start with #`);
    }
    check((await plate.locator('textarea[data-k="features"]').inputValue()).split('\n').length === 5, 'features are capped at 5 by the server');

    await plate.locator('[data-tab="en"]').click();
    await plate.locator('textarea[data-k="title"]').fill('Edited green mug title');
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#dlCsv')]);
    const buf = fs.readFileSync(await dl.path());
    const csv = buf.toString('utf8');
    check(dl.suggestedFilename() === 'product-listings.csv', 'CSV downloads as product-listings.csv');
    check(buf[0] === 0xef && buf[1] === 0xbb && buf[2] === 0xbf, 'CSV starts with a UTF-8 BOM (Excel shows Arabic correctly)');
    check(csv.includes('Edited green mug title'), 'CSV contains the edited title');
    check((csv.match(/"2 - كوب قهوة أخضر","(ar|en|tr)"/g) || []).length === 3, 'CSV has one row per language for the product');
    await page.click('#copyCsv');
    check((await page.evaluate(() => navigator.clipboard.readText())).includes('Edited green mug title'), 'Copy CSV still works');

    await page.click('#langBtn');
    check((await page.getAttribute('html', 'lang')) === 'en' && (await page.getAttribute('html', 'dir')) === 'ltr', 'interface switches to English, left to right');
    check((await page.textContent('h1')) === 'Product listing writer' && (await page.textContent('#aiTxt')) === 'Assistant ready', 'English texts are shown');
    check((await page.textContent('#dlCsv')) === 'Download CSV', 'buttons are translated');
    check(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));

    console.log('Errors and recovery');
    const page2 = await ctx.newPage();
    const errors2 = collectErrors(page2);
    await page2.goto(APP);
    await page2.waitForSelector('#dot.on');
    // Turkish fails (the mock answers 529 twice), the others arrive; retry fills Turkish.
    const r1 = await uploadAndGenerate(page2, photo, 'mug mock:fail-tr');
    await waitIdle(page2, r1.id);
    check((await r1.plate.locator('[data-tab="tr"][data-st="err"]').count()) === 1, 'failed language is marked on its tab');
    check((await r1.plate.locator('[data-tab="ar"]:not([data-st])').count()) === 1, 'other languages still arrive');
    await r1.plate.locator('[data-tab="tr"]').click();
    check((await r1.plate.locator('.terr .err').textContent()) === 'The service is busy right now. Try again in a moment.', 'busy message shown on the failed tab');
    await r1.plate.locator('textarea.notes').fill('mug');
    await r1.plate.locator('[data-retry="tr"]').click();
    await waitIdle(page2, r1.id);
    check((await r1.plate.locator('[data-tab="tr"]:not([data-st])').count()) === 1 && (await r1.plate.locator('textarea[data-k="title"]').inputValue()).includes('Kupa'), 'retry fills the Turkish tab');

    // Stop while writing: nothing is left running and the product goes back to idle.
    const r2 = await uploadAndGenerate(page2, photo, 'mug mock:slow');
    await r2.plate.locator('.wait .secs').waitFor();
    await page2.waitForTimeout(1300);
    const secs = await r2.plate.locator('.wait .secs').textContent();
    check(/^[1-9]\d* s$/.test(secs), 'seconds counter counts up while writing: ' + secs);
    await r2.plate.locator('[data-stop]').click({ timeout: 5000 });
    await waitIdle(page2, r2.id, 5000);
    check((await r2.plate.locator('.idle').count()) === 1 && (await r2.plate.locator('[data-tab]').count()) === 0, 'Stop cancels the requests and the product goes back to idle');

    // Friendly messages for the other errors (responses replaced in the browser).
    const scenario = async (label, handler, expected) => {
      await page2.route('**/api/generate.php', handler);
      const r = await uploadAndGenerate(page2, photo, 'mug');
      await waitIdle(page2, r.id);
      const msg = (await r.plate.locator('.out > .err').textContent()) || '';
      check(expected.test(msg), `${label}: "${msg}"`);
      await page2.unroute('**/api/generate.php');
      await r.plate.locator('[data-del]').click();
    };
    const json = (status, body) => (route) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    await scenario('too many requests', json(429, { error: 'rate_limited', retry_after: 1800 }), /^The request limit has been reached for now\. Try again after \d{1,2}:\d\d/);
    await scenario('too many requests, short wait', json(429, { error: 'rate_limited', retry_after: 40 }), /Wait a minute and try again/);
    await scenario('service busy', json(503, { error: 'busy' }), /service is busy/);
    await scenario('photo rejected', json(400, { error: 'photo_rejected' }), /Couldn’t read the photo/);
    await scenario('network error', (route) => route.abort('internetdisconnected'), /Couldn’t reach the server/);
    await scenario('demo paused', json(503, { error: 'paused' }), /demo is paused/);
    check((await page2.textContent('#aiTxt')) === 'Demo paused', 'status dot switches to "Demo paused"');
    // The "try again after" time stays put when the page re-renders later.
    await page2.route('**/api/generate.php', json(429, { error: 'rate_limited', retry_after: 1800 }));
    const r5 = await uploadAndGenerate(page2, photo, 'mug');
    await waitIdle(page2, r5.id);
    const firstMsg = await r5.plate.locator('.out > .err').textContent();
    await page2.evaluate(() => { const real = Date.now.bind(Date); Date.now = () => real() + 20 * 60 * 1000; });
    await page2.click('#langBtn');
    await page2.click('#langBtn');
    const laterMsg = await r5.plate.locator('.out > .err').textContent();
    check(firstMsg === laterMsg, `"try again after" time doesn't move on re-render: ${laterMsg}`);
    await page2.unroute('**/api/generate.php');
    const unexpected = errors2.filter((e) => !/Failed to load resource/.test(e));
    check(unexpected.length === 0, 'no unexpected console errors' + (unexpected.length ? ': ' + unexpected.join(' | ') : ''));
    await page2.close();

    console.log('Stop, double-click and copy');
    const page3 = await ctx.newPage();
    const errors4 = collectErrors(page3);
    await page3.goto(APP);
    await page3.waitForSelector('#dot.on');
    // A double-click on "Write listing" must not stop the run (Stop takes the button's place).
    const before = await page3.locator('.plate:not(#ex)').count();
    await page3.setInputFiles('#file', photo);
    const p6 = page3.locator('.plate:not(#ex)').nth(before);
    await p6.waitFor();
    const id6 = await p6.getAttribute('id');
    const r6 = { plate: page3.locator('#' + id6), id: id6 };
    await r6.plate.locator('textarea.notes').fill('mug mock:slow');
    await r6.plate.locator('[data-gen]').dblclick();
    await page3.waitForTimeout(300);
    check((await r6.plate.locator('[data-stop]').count()) === 1, 'a double-click on "Write listing" keeps the run going');
    await waitIdle(page3, r6.id);
    check((await r6.plate.locator('[data-tab]:not([data-st])').count()) === 3, 'and the listing arrives in three languages');
    // Stop after one language arrived keeps the earlier listing (and edits) for the others.
    await r6.plate.locator('[data-tab="en"]').click();
    await r6.plate.locator('textarea[data-k="title"]').fill('My edited English title');
    await r6.plate.locator('textarea.notes').fill('mug mock:slow-but-ar');
    const rewriteAt = Date.now();
    await r6.plate.locator('[data-gen]').click();
    await r6.plate.locator('[data-tab="ar"]:not([data-st])').waitFor();
    await page3.waitForTimeout(Math.max(0, 800 - (Date.now() - rewriteAt))); // past the double-click guard
    await r6.plate.locator('[data-stop]').click({ timeout: 5000 });
    await waitIdle(page3, r6.id, 5000);
    await r6.plate.locator('[data-tab="en"]').click();
    check((await r6.plate.locator('[data-tab]:not([data-st])').count()) === 3 && (await r6.plate.locator('textarea[data-k="title"]').inputValue()) === 'My edited English title',
      'Stop keeps the earlier listing, edits included, for languages that had not arrived');
    // Copy twice quickly: the label still comes back.
    const copyBtn = r6.plate.locator('[data-copy="title"]');
    await copyBtn.click();
    await copyBtn.click();
    await page3.waitForTimeout(1700);
    check((await copyBtn.textContent()) === 'Copy', 'the "Copied" label goes back to "Copy" after two quick clicks');
    // Stop during "Generate all" ends the whole batch.
    const g = [];
    for (let i = 0; i < 2; i++) {
      const n = await page3.locator('.plate:not(#ex)').count();
      await page3.setInputFiles('#file', photo);
      const pl = page3.locator('.plate:not(#ex)').nth(n);
      await pl.waitFor();
      await pl.locator('textarea.notes').fill('mug mock:slow');
      g.push(page3.locator('#' + (await pl.getAttribute('id'))));
    }
    await page3.click('#genAll');
    await g[0].locator('[data-stop]').waitFor();
    await page3.waitForTimeout(800);
    await g[0].locator('[data-stop]').click({ timeout: 5000 });
    await page3.waitForFunction(() => !document.getElementById('genAll').disabled, null, { timeout: 10000 });
    check((await g[1].locator('.idle').count()) === 1 && (await page3.locator('[data-stop]').count()) === 0, 'Stop during "Generate all" ends the batch');
    // A photo dropped outside the drop area doesn't open in the tab.
    const prevented = await page3.evaluate(() => {
      const ev = new DragEvent('drop', { dataTransfer: new DataTransfer(), bubbles: true, cancelable: true });
      document.querySelector('h1').dispatchEvent(ev);
      return ev.defaultPrevented;
    });
    check(prevented, 'a photo dropped outside the drop area does not replace the page');
    check(errors4.length === 0, 'no console errors' + (errors4.length ? ': ' + errors4.join(' | ') : ''));
    await ctx.close();

    console.log('Phone');
    const phone = await browser.newContext({ ...devices['iPhone 13'] });
    const mp = await phone.newPage();
    const errors3 = collectErrors(mp);
    await mp.goto(APP);
    await mp.waitForSelector('#dot.on');
    check(await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no sideways scrolling on a phone');
    const box = await mp.locator('#file').boundingBox();
    const drop = await mp.locator('#drop').boundingBox();
    // The invisible file input fills everything inside the 2 px dashed border.
    check(box && drop && box.width >= drop.width - 5 && box.height >= drop.height - 5, 'the whole upload area opens the photo picker');
    const r3 = await uploadAndGenerate(mp, photo, 'كوب قهوة أخضر');
    await waitIdle(mp, r3.id);
    check((await r3.plate.locator('[data-tab]').count()) === 3, 'phone: listing written in three languages');
    check(await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'still no sideways scrolling with a listing');
    check(errors3.length === 0, 'phone: no console errors' + (errors3.length ? ': ' + errors3.join(' | ') : ''));
    await phone.close();

    if (SHOTS) {
      await screenshots(browser, photo);
      console.log('Screenshots saved to ' + SHOTS);
    }
  } finally {
    await browser.close();
    fs.rmSync(TMP, { recursive: true, force: true });
  }
  console.log(failures ? `${failures} browser check(s) failed` : 'All browser checks passed');
  process.exit(failures ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
