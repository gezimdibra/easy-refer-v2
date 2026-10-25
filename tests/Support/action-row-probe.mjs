#!/usr/bin/env node
/*
 * Action-row geometry probe (Train 2 fix round, T2R-01; the
 * standing action-row geometry pin in knowledge/build-and-test.md).
 *
 * Driven by Tests\Feature\Owner\ActionCenterGeometryTest. Serves
 * the Action Center page the test client rendered (plus the
 * app's public/ assets) on an ephemeral loopback port, opens it
 * in the WebKit rig at each configured phone width, and measures
 * every row that pairs a text line with action buttons:
 *
 *   - the line's box width against the card's content width
 *     (a stacked row's line spans the full content width; the
 *     T2R-01 crush left it at ~98px of ~340px);
 *   - every action element's box (links and buttons) tested
 *     for intersection with the line's box (the crush painted
 *     buttons over the text).
 *
 * Usage: node action-row-probe.mjs <config.json>
 * Config: { pagesDir, publicDir, rigDir, widths: [390, 375] }
 * Stdout: one JSON object keyed by width: { rows: [...] }.
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
  if (path === '/actions') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(readFileSync(join(config.pagesDir, 'actions.html')));
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

const out = {};
for (const width of config.widths) {
  await page.setViewportSize({ width, height: 2600 });
  await page.goto(`${base}/actions`, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(200);
  out[width] = await page.evaluate(() => {
    const round = n => Math.round(n * 10) / 10;
    // A row is a card holding both a text line (a <p>) and at
    // least one action (a link or a button). The healthy-state
    // card holds a line alone and is not a row.
    const cards = [...document.querySelectorAll('.card')]
      .filter(c => c.querySelector('p') && (c.querySelector('a') || c.querySelector('button')));
    const rows = cards.map(card => {
      const cs = getComputedStyle(card);
      const contentW = card.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
      const line = card.querySelector('p');
      const lr = line.getBoundingClientRect();
      const eps = 0.5;
      const actions = [...card.querySelectorAll('a, button')].map(el => {
        const r = el.getBoundingClientRect();
        const overlaps = r.left < lr.right - eps && r.right > lr.left + eps
          && r.top < lr.bottom - eps && r.bottom > lr.top + eps;
        return {
          label: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30),
          w: round(r.width),
          overlapsLine: overlaps,
        };
      });
      return {
        line: (line.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60),
        cardW: round(card.getBoundingClientRect().width),
        contentW: round(contentW),
        lineW: round(lr.width),
        actions,
        overlapCount: actions.filter(a => a.overlapsLine).length,
      };
    });
    return { rows };
  });
}

console.log(JSON.stringify(out));
await browser.close();
server.close();
