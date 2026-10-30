#!/usr/bin/env node
/*
 * Customer stat-strip geometry probe (post-r26 B1; the r26
 * Gate 3 MINOR pin). Driven by
 * Tests\Feature\Owner\OverviewGapTest. Serves the customer
 * detail page the test client rendered (plus the app's
 * public/ assets) on an ephemeral loopback port, opens it in
 * the WebKit rig at 375 and 390 in light and dark, and
 * measures every compact stat label: its scroll width against
 * its client width (a label wider than its box has overflowed
 * its tile) and its box against its tile's box.
 *
 * Usage: node customer-stat-probe.mjs <config.json>
 * Config: { pagesDir, publicDir, rigDir, widths: [375, 390] }
 * Stdout: one JSON object keyed by "<width>-<scheme>":
 * { labels: [...] }.
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
  if (path === '/customer') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(readFileSync(join(config.pagesDir, 'customer.html')));
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
const out = {};
for (const scheme of ['light', 'dark']) {
  const ctx = await browser.newContext({ colorScheme: scheme });
  ctx.setDefaultTimeout(30000);
  const page = await ctx.newPage();
  for (const width of config.widths) {
    await page.setViewportSize({ width, height: 1600 });
    await page.goto(`${base}/customer`, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(200);
    out[`${width}-${scheme}`] = await page.evaluate(() => {
      const round = n => Math.round(n * 10) / 10;
      const labels = [...document.querySelectorAll('.tile-label-compact')].map(el => {
        const r = el.getBoundingClientRect();
        const tile = el.parentElement.getBoundingClientRect();
        return {
          text: (el.textContent || '').trim(),
          scrollW: el.scrollWidth,
          clientW: el.clientWidth,
          left: round(r.left),
          right: round(r.right),
          tileLeft: round(tile.left),
          tileRight: round(tile.right),
        };
      });
      return { labels };
    });
  }
  await ctx.close();
}

console.log(JSON.stringify(out));
await browser.close();
server.close();
