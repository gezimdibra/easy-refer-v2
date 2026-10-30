#!/usr/bin/env node
/*
 * Steps-bar / navbar coupling probe (Release 40, standing check).
 *
 * Driven by Tests\Feature\StepsBarHeaderSyncTest. Serves the home
 * page the test client rendered (plus the app's public/ assets) on
 * an ephemeral loopback port and, at 390x664, 390x700, 390x844 and
 * 1440x900, walks the real scroll state machine:
 *
 *   1. stepwise scroll down in 120px steps (each step is a real
 *      scroll event the SL-11 hide script reads) until the header
 *      hides and the steps bar is stuck deep in the story;
 *   2. settled hidden-state measurement of the bar's viewport top;
 *   3. one 120px step up (header returns), settled visible-state
 *      measurement;
 *   4. one 120px step down again with 20ms sampling through the
 *      200ms hide transition (header bottom edge vs bar top);
 *   5. settled re-hidden measurement (the settle leg).
 *
 * Stdout: one JSON object keyed by geometry.
 * Config: { pagesDir, publicDir, rigDir }
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
  if (path === '/' || path === '/home') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(readFileSync(join(config.pagesDir, 'home.html')));
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

const GEOMS = [
  { w: 390, h: 664 }, { w: 390, h: 700 }, { w: 390, h: 844 }, { w: 1440, h: 900 },
];

const measure = () => {
  const hdr = document.getElementById('site-header');
  const bar = document.querySelector('.hiw-progress');
  const hb = hdr.getBoundingClientRect();
  const bb = bar.getBoundingClientRect();
  return {
    hidden: hdr.classList.contains('site-header-hidden'),
    headerTop: Math.round(hb.top * 10) / 10,
    headerBottom: Math.round(hb.bottom * 10) / 10,
    barTop: Math.round(bb.top * 10) / 10,
    scrollY: window.scrollY,
  };
};

/* Frame-aligned sampling: reads happen inside rAF, after the
 * frame's layout, which is the position a reader actually sees.
 * (An interval sampler that forces synchronous layout between
 * frames reads mid-recalc positions and invents up to ~15px of
 * wobble that no frame ever paints — measured during the R40
 * build; the rAF reads below are the coupling of record.) */
const sampler = () => new Promise((resolve) => {
  const samples = [];
  const t0 = performance.now();
  const tick = () => {
    const hdr = document.getElementById('site-header');
    const bar = document.querySelector('.hiw-progress');
    samples.push({
      t: Math.round(performance.now() - t0),
      headerBottom: Math.round(hdr.getBoundingClientRect().bottom * 10) / 10,
      barTop: Math.round(bar.getBoundingClientRect().top * 10) / 10,
      hidden: hdr.classList.contains('site-header-hidden'),
    });
    if (performance.now() - t0 < 460) requestAnimationFrame(tick);
    else resolve(samples);
  };
  requestAnimationFrame(tick);
});

const browser = await webkit.launch();
const out = {};

for (const g of GEOMS) {
  const ctx = await browser.newContext({ viewport: { width: g.w, height: g.h } });
  const page = await ctx.newPage();
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(250);

  const storyDocTop = await page.evaluate(() => {
    const s = document.getElementById('hiw-story');
    return s.getBoundingClientRect().top + window.scrollY;
  });

  // Stepwise down: 120px steps are what flips SL-11 (y > 96,
  // delta > 4) exactly as a reader's scroll does.
  const target = Math.round(storyDocTop) + 300;
  let y = 0;
  while (y < target) {
    y = Math.min(y + 120, target);
    await page.evaluate((yy) => window.scrollTo(0, yy), y);
    await page.waitForTimeout(90);
  }
  await page.waitForTimeout(320); // outlast the 200ms transition
  const hidden = await page.evaluate(measure);

  // One step up: header returns; settle, then measure visible.
  await page.evaluate(() => window.scrollBy(0, -120));
  await page.waitForTimeout(350);
  const visible = await page.evaluate(measure);

  // One step down with transition sampling, then settle: re-hidden.
  const sampling = page.evaluate(sampler);
  await page.waitForTimeout(30);
  await page.evaluate(() => window.scrollBy(0, 120));
  const hideSamples = await sampling;
  await page.waitForTimeout(320);
  const hiddenAgain = await page.evaluate(measure);

  out[`${g.w}x${g.h}`] = { hidden, visible, hiddenAgain, hideSamples };
  await ctx.close();
}

await browser.close();
server.close();
process.stdout.write(JSON.stringify(out));
