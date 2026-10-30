#!/usr/bin/env node
/*
 * QR poster-mix geometry probe (P3, benchmark pins M-1 + M-5 and
 * the standing action-row geometry pin).
 *
 * Driven by Tests\Feature\Owner\QrPosterMixGeometryTest. Serves
 * the QR page the test client rendered on an ephemeral loopback
 * port and measures, in the WebKit rig:
 *
 *   M-1 at 390x844 (viewport only): the complete Start here
 *   panel sits inside the first screenful and no by-spot Print
 *   button does (the one-primary-action benefit is a layout
 *   fact, not a hope).
 *   M-5 at 390, 375, 1440: every row action in the by-spot rows
 *   and the Also available rows is at least 44px tall.
 *   Standing pin: no by-spot row action overlaps the row's text
 *   block at any width.
 *
 * Usage: node qr-mix-probe.mjs <config.json>
 * Config: { pagesDir, publicDir, rigDir, widths: [390, 375, 1440] }
 * Stdout: one JSON object: { firstScreen: {...}, widths: {...} }.
 */
import { createRequire } from 'node:module';
import { createServer } from 'node:http';
import { readFileSync, existsSync, statSync } from 'node:fs';
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
  if (path === '/qr') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(readFileSync(join(config.pagesDir, 'qr.html')));
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
const ctx = await browser.newContext();
ctx.setDefaultTimeout(30000);
const page = await ctx.newPage();

const out = { firstScreen: null, widths: {} };

// M-1: the true first screenful on the owner's phone size.
await page.setViewportSize({ width: 390, height: 844 });
await page.goto(`${base}/qr`, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(200);
out.firstScreen = await page.evaluate(() => {
  const panel = document.querySelector('#start-here');
  const pr = panel.getBoundingClientRect();
  const bySpotPrints = [...document.querySelectorAll('#by-spot a.btn')].map(a => {
    const r = a.getBoundingClientRect();
    return { label: a.textContent.trim(), top: r.top, bottom: r.bottom };
  });
  const inViewport = r => r.top < 844 && r.bottom > 0;
  // M-1 v2 (Amendment 41): the pair block (#start-here-pair)
  // lives inside the same panel by ratified design, so the
  // first-screenful measurement also reports the primary
  // step's completion point and the pair's position inside
  // the panel.
  const primary = panel.querySelector('.btn-primary');
  const primR = primary ? primary.getBoundingClientRect() : null;
  const pair = document.querySelector('#start-here-pair');
  const pairR = pair ? pair.getBoundingClientRect() : null;
  return {
    panelTop: pr.top,
    panelBottom: pr.bottom,
    panelComplete: pr.top >= 0 && pr.bottom <= 844,
    primaryBottom: primR ? primR.bottom : null,
    pairTop: pairR ? pairR.top : null,
    pairBottom: pairR ? pairR.bottom : null,
    bySpotPrintsInFirstScreen: bySpotPrints.filter(inViewport).map(x => x.label),
  };
});

for (const width of config.widths) {
  await page.setViewportSize({ width, height: 2600 });
  await page.goto(`${base}/qr`, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(200);
  out.widths[width] = await page.evaluate(() => {
    const round = n => Math.round(n * 10) / 10;
    const overlaps = (a, b) => a.left < b.right - 0.5 && b.left < a.right - 0.5 && a.top < b.bottom - 0.5 && b.top < a.bottom - 0.5;
    const rows = [...document.querySelectorAll('li[data-placement]')].map(li => {
      const textBlock = li.querySelector('.flex.items-start');
      const tr = textBlock.getBoundingClientRect();
      const actions = [...li.querySelectorAll('a, button')].filter(el => el.offsetParent !== null).map(el => {
        const r = el.getBoundingClientRect();
        return { label: el.textContent.trim().replace(/\s+/g, ' '), h: round(r.height), overlapsText: overlaps(r, tr) };
      });
      return { placement: li.dataset.placement, actions };
    });
    const extras = [...document.querySelectorAll('#also-available a')].map(el => {
      const r = el.getBoundingClientRect();
      return { label: el.textContent.trim(), h: round(r.height) };
    });
    return { rows, extras };
  });
}

await browser.close();
server.close();
console.log(JSON.stringify(out));
