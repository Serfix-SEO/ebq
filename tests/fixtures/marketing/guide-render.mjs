
// Guide screenshot renderer: fixture HTML -> annotated PNG.
import puppeteer from '/opt/ebq-intelegence/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';
import { readFileSync, writeFileSync, mkdirSync } from 'fs';

const [,, DIR, OUT] = process.argv;
mkdirSync(OUT, { recursive: true });
const CSS = 'file:///var/www/ebq/public/build/assets/app-lhs8Ayrz.css';

function helpers() {
  window.__byText = (sel, needle, minW, minH) => {
    const els = [...document.querySelectorAll(sel)].filter(e => {
      if (!e.textContent.trim().includes(needle)) return false;
      const r = e.getBoundingClientRect();
      if (r.width < (minW || 8) || r.height < (minH || 8)) return false;
      return true;
    });
    els.sort((a, b) => a.textContent.length - b.textContent.length);
    return els[0] || null;
  };
  window.__annotate = (el, n) => {
    if (!el) return;
    const r = el.getBoundingClientRect();
    const ring = document.createElement('div');
    ring.style.position = 'absolute';
    ring.style.left = (r.left + scrollX - 6) + 'px';
    ring.style.top = (r.top + scrollY - 6) + 'px';
    ring.style.width = (r.width + 12) + 'px';
    ring.style.height = (r.height + 12) + 'px';
    ring.style.border = '3px solid #F26419';
    ring.style.borderRadius = '12px';
    ring.style.pointerEvents = 'none';
    ring.style.zIndex = 9999;
    ring.style.boxShadow = '0 0 0 4px rgba(242,100,25,.14)';
    document.body.appendChild(ring);
    const b = document.createElement('div');
    b.textContent = n;
    b.style.position = 'absolute';
    b.style.left = (r.left + scrollX - 17) + 'px';
    b.style.top = (r.top + scrollY - 17) + 'px';
    b.style.width = '28px';
    b.style.height = '28px';
    b.style.borderRadius = '9999px';
    b.style.background = '#F26419';
    b.style.color = '#fff';
    b.style.font = '800 15px/28px ui-sans-serif,system-ui';
    b.style.textAlign = 'center';
    b.style.zIndex = 10000;
    b.style.boxShadow = '0 2px 6px rgba(0,0,0,.25)';
    document.body.appendChild(b);
  };
}

const SHOTS = JSON.parse(readFileSync(new URL('./guide-shots.json', import.meta.url), 'utf8'));
const browser = await puppeteer.launch({ executablePath: '/usr/bin/google-chrome',
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--allow-file-access-from-files'] });

for (const shot of SHOTS) {
  const page = await browser.newPage();
  await page.setViewport({ width: shot.width || 1240, height: 900, deviceScaleFactor: 2 });
  const body = readFileSync(DIR + '/' + shot.fixture + '.html', 'utf8');
  const html = '<!doctype html><html><head><meta charset="utf-8">'
    + '<link rel="stylesheet" href="' + CSS + '">'
    + '<style>[x-cloak]{display:none!important} body{background:#fff} .animate-spin{animation:none!important} [wire\\:loading]{display:none!important} [wire\\:loading\\.flex]{display:none!important}</style>'
    + '</head><body><div style="padding:20px">' + body + '</div></body></html>';
  const tmp = OUT + '/__tmp.html';
  writeFileSync(tmp, html);
  await page.goto('file://' + tmp, { waitUntil: 'networkidle0', timeout: 60000 });
  await page.evaluate(helpers);
  if (shot.prep) await page.evaluate(shot.prep);
  await new Promise(r => setTimeout(r, 250));
  let i = 0;
  for (const a of (shot.annotations || [])) {
    i++;
    await page.evaluate((a, n) => {
      let el;
      if (a.text) {
        el = window.__byText(a.sel, a.text, a.minW, a.minH);
      } else {
        el = [...document.querySelectorAll(a.sel)].find(e => {
          const r = e.getBoundingClientRect();
          return r.width > 8 && r.height > 8;
        }) || null;
      }
      for (let k = 0; el && k < (a.up || 0); k++) el = el.parentElement;
      window.__annotate(el, n);
    }, a, i);
  }
  await new Promise(r => setTimeout(r, 100));
  let clip = null;
  if (shot.target) {
    clip = await page.evaluate((t) => {
      let el = t.text ? window.__byText(t.sel, t.text, t.minW, t.minH) : document.querySelector(t.sel);
      for (let k = 0; el && k < (t.up || 0); k++) el = el.parentElement;
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { x: Math.max(0, r.left + scrollX - 14), y: Math.max(0, r.top + scrollY - 14), width: r.width + 28, height: r.height + 28 };
    }, shot.target);
  }
  if (!clip) {
    const b = await page.evaluate(() => ({ width: document.body.scrollWidth, height: document.body.scrollHeight }));
    clip = { x: 0, y: 0, width: b.width, height: b.height };
  }
  if (shot.maxH) clip.height = Math.min(clip.height, shot.maxH);
  if (shot.padBottom) clip.height += shot.padBottom;
  await page.screenshot({ path: OUT + '/' + shot.name + '.png', clip });
  console.log('shot', shot.name, Math.round(clip.width) + 'x' + Math.round(clip.height));
  await page.close();
}
await browser.close();
console.log('DONE');
