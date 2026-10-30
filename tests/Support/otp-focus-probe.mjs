#!/usr/bin/env node
/*
 * OTP focus probe (owner-findings fix wave, OTP-01).
 *
 * Driven by Tests\Feature\OtpFocusProbeTest. Serves the
 * verification page the test client rendered (plus the app's
 * public/ assets, including the real built flow.js) on an
 * ephemeral loopback port, opens it in the WebKit rig at
 * iPhone size (390x844, iPhone UA, touch), and acts out the
 * owner's report:
 *
 *   1. Tap the FOURTH box, as a thumb lands mid-group.
 *   2. Read which box holds focus (document.activeElement).
 *   3. Type the six digits, as an autofilled code would enter
 *      them one keystroke at a time from the focused box.
 *   4. Read every box's value and the composed hidden field.
 *
 * Pre-fix, the tap leaves focus on box 4 and the digits pile
 * into boxes 4-6, losing the last three. The fix (tapping any
 * box starts at the first box) makes the tap land on box 1
 * and the digits fill all six boxes in order.
 *
 * Usage: node otp-focus-probe.mjs <config.json>
 * Config: { pagesDir, publicDir, rigDir, width, shotsDir? }
 * Stdout: one JSON object:
 *   { activeIndexAfterTap, values: [...], composed }
 */
import { createRequire } from 'node:module';
import { createServer } from 'node:http';
import { readFileSync, existsSync, statSync, mkdirSync } from 'node:fs';
import { join, normalize, extname } from 'node:path';
import { homedir } from 'node:os';

const config = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const rigDir = config.rigDir || join(homedir(), 'workspace', 'webkit-spike');
process.env.PLAYWRIGHT_BROWSERS_PATH = process.env.PLAYWRIGHT_BROWSERS_PATH || join(rigDir, 'browsers');
const require = createRequire(join(rigDir, 'package.json'));
const { webkit } = require('playwright');

const MIME = {
  '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2',
  '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.png': 'image/png',
  '.jpg': 'image/jpeg', '.json': 'application/json', '.html': 'text/html',
};

const server = createServer((req, res) => {
  let path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (path === '/verify') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(readFileSync(join(config.pagesDir, 'verify.html')));
  }
  if (path.startsWith('/new/')) path = path.slice(4);
  const file = normalize(join(config.publicDir, path));
  if (file.startsWith(config.publicDir) && existsSync(file) && statSync(file).isFile()) {
    res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' });
    return res.end(readFileSync(file));
  }
  res.writeHead(404);
  res.end();
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;

const browser = await webkit.launch();
const context = await browser.newContext({
  viewport: { width: config.width || 390, height: 844 },
  deviceScaleFactor: 3,
  isMobile: true,
  hasTouch: true,
  userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
});
const page = await context.newPage();
await page.goto(base + '/verify', { waitUntil: 'load' });
// The flow module is deferred; Alpine starting is the sign
// the OTP wiring has run.
await page.waitForFunction(() => window.Alpine !== undefined, null, { timeout: 10000 });
await page.waitForTimeout(250);

const boxes = page.locator('[data-otp-box]');
const count = await boxes.count();

// The owner's tap: the middle of the group, not the first box.
await boxes.nth(3).tap();
await page.waitForTimeout(150);

const activeIndexAfterTap = await page.evaluate(() => {
  const all = Array.from(document.querySelectorAll('[data-otp-box]'));
  return all.indexOf(document.activeElement);
});

if (config.shotsDir) {
  mkdirSync(config.shotsDir, { recursive: true });
  await page.screenshot({ path: join(config.shotsDir, 'otp-after-tap.png') });
}

await page.keyboard.type('123456', { delay: 45 });
await page.waitForTimeout(200);

const values = await page.evaluate(() =>
  Array.from(document.querySelectorAll('[data-otp-box]')).map(b => b.value));
const composed = await page.evaluate(() =>
  (document.querySelector('[data-otp-value]') || {}).value ?? null);

if (config.shotsDir) {
  await page.screenshot({ path: join(config.shotsDir, 'otp-after-typing.png') });
}

console.log(JSON.stringify({ boxCount: count, activeIndexAfterTap, values, composed }));

await browser.close();
server.close();
