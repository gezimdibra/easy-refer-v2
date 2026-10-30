#!/usr/bin/env node
/*
 * Poster print-fit probe (Train 1 fix round, standing check).
 *
 * Driven by Tests\Feature\Owner\PosterPrintFitTest. Serves the
 * poster pages the test client rendered (plus the app's public/
 * assets) on an ephemeral loopback port, opens each variant in
 * the WebKit rig at its named @page width, and measures:
 *
 *   pass 1 (screen media, tall viewport): every .poster-sheet
 *     box, and every descendant element's box tested against its
 *     sheet's box (the print-geometry assertion);
 *   pass 2 (print media): each sheet's computed margins and the
 *     actions row's display (the pagination inputs: a sheet
 *     occupies h + marginTop + marginBottom of its page).
 *
 * Pagination is proven arithmetically, the way the Train 1
 * review's metrology proved it negatively: sheets after the
 * first carry break-before: page and print margins are zero, so
 * a sheet whose box fits its page box occupies exactly one page.
 *
 * Usage: node poster-print-probe.mjs <config.json>
 * Config: { pagesDir, publicDir, rigDir,
 *           variants: { counter: { pageW, pageH }, ... } }
 * Stdout: one JSON object keyed by variant.
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
  const poster = path.match(/^\/poster-([a-z]+)$/);
  if (poster) {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(readFileSync(join(config.pagesDir, poster[1] + '.html')));
  }
  if (path.startsWith('/new/')) path = path.slice(4);
  if (path === '/auth/qr/image') {
    res.writeHead(200, { 'Content-Type': 'image/svg+xml' });
    return res.end(readFileSync(join(config.pagesDir, 'qr.svg')));
  }
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
for (const [variant, spec] of Object.entries(config.variants)) {
  await page.setViewportSize({ width: Math.round(spec.pageW), height: 2800 });
  await page.goto(`${base}/poster-${variant}`, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(200);
  const sheets = await page.evaluate(() => {
    return [...document.querySelectorAll('.poster-sheet')].map(sheet => {
      const sr = sheet.getBoundingClientRect();
      const violations = [];
      sheet.querySelectorAll('*').forEach(el => {
        const r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) return;
        const eps = 1.5;
        const over = {
          left: Math.round((sr.left - r.left) * 10) / 10,
          right: Math.round((r.right - sr.right) * 10) / 10,
          top: Math.round((sr.top - r.top) * 10) / 10,
          bottom: Math.round((r.bottom - sr.bottom) * 10) / 10,
        };
        if (over.left > eps || over.right > eps || over.top > eps || over.bottom > eps) {
          violations.push({
            tag: el.tagName.toLowerCase(),
            cls: (typeof el.className === 'string' ? el.className : '').slice(0, 46),
            text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40),
            over,
          });
        }
      });
      // Card-fit measurements (Amendment 43): the sheet's own
      // content height against its box, every text block's
      // span relative to the sheet top, and the typed fallback
      // paragraph's span — the numbers a clipped line shows up
      // in even when no element box crosses the sheet box (a
      // paragraph's box can end inside while its last wrapped
      // line is cut by the sheet's overflow clip).
      const paras = [...sheet.querySelectorAll('p, h1')].map(el => {
        const r = el.getBoundingClientRect();
        return {
          tag: el.tagName.toLowerCase(),
          text: (el.textContent || '').trim().replace(/\s+/g, ' '),
          topRel: Math.round((r.top - sr.top) * 10) / 10,
          bottomRel: Math.round((r.bottom - sr.top) * 10) / 10,
          h: Math.round(r.height * 10) / 10,
        };
      });
      const fbEl = [...sheet.querySelectorAll('p')].find(el => (el.textContent || '').trim().startsWith("Can't scan?"));
      const fallback = fbEl ? {
        text: (fbEl.textContent || '').trim().replace(/\s+/g, ' '),
        topRel: Math.round((fbEl.getBoundingClientRect().top - sr.top) * 10) / 10,
        bottomRel: Math.round((fbEl.getBoundingClientRect().bottom - sr.top) * 10) / 10,
      } : null;
      return {
        w: Math.round(sr.width * 10) / 10,
        h: Math.round(sr.height * 10) / 10,
        clientH: sheet.clientHeight,
        scrollH: sheet.scrollHeight,
        violations,
        paras,
        fallback,
      };
    });
  });
  // The multi-up sheet's cards live in fixed cut-guide boxes
  // inside one tall sheet, so sheet-level checks cannot see a
  // clipped card. Measure every cut guide the same way.
  const guides = await page.evaluate(() => {
    return [...document.querySelectorAll('.cut-guide')].map(guide => {
      const gr = guide.getBoundingClientRect();
      const violations = [];
      guide.querySelectorAll('*').forEach(el => {
        const r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) return;
        const eps = 1.5;
        const over = {
          left: Math.round((gr.left - r.left) * 10) / 10,
          right: Math.round((r.right - gr.right) * 10) / 10,
          top: Math.round((gr.top - r.top) * 10) / 10,
          bottom: Math.round((r.bottom - gr.bottom) * 10) / 10,
        };
        if (over.left > eps || over.right > eps || over.top > eps || over.bottom > eps) {
          violations.push({
            tag: el.tagName.toLowerCase(),
            cls: (typeof el.className === 'string' ? el.className : '').slice(0, 46),
            text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40),
            over,
          });
        }
      });
      const fbEl = [...guide.querySelectorAll('p')].find(el => (el.textContent || '').trim().startsWith("Can't scan?"));
      const fallback = fbEl ? {
        text: (fbEl.textContent || '').trim().replace(/\s+/g, ' '),
        topRel: Math.round((fbEl.getBoundingClientRect().top - gr.top) * 10) / 10,
        bottomRel: Math.round((fbEl.getBoundingClientRect().bottom - gr.top) * 10) / 10,
      } : null;
      return {
        w: Math.round(gr.width * 10) / 10,
        h: Math.round(gr.height * 10) / 10,
        clientH: guide.clientHeight,
        scrollH: guide.scrollHeight,
        violations,
        fallback,
      };
    });
  });
  await page.emulateMedia({ media: 'print' });
  await page.waitForTimeout(120);
  const print = await page.evaluate(() => {
    const act = document.querySelector('.poster-actions');
    return {
      actionsDisplay: act ? getComputedStyle(act).display : 'absent',
      margins: [...document.querySelectorAll('.poster-sheet')].map(s => {
        const cs = getComputedStyle(s);
        return { mt: parseFloat(cs.marginTop), mb: parseFloat(cs.marginBottom) };
      }),
    };
  });
  await page.emulateMedia({ media: 'screen' });
  out[variant] = { sheets, print, guides };
}

console.log(JSON.stringify(out));
await browser.close();
server.close();
