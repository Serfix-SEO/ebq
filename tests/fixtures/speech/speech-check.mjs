/**
 * Behaviour check for the "Listen" article reader (resources/js/article-speech.js).
 *
 * There is no JS test framework in this repo (package.json has only `vite
 * build`), and headless Chrome has no speech voices, so this drives the REAL
 * BUILT bundle in Chrome against a STUBBED speech engine and asserts what it
 * asked the engine to do. That is as close to the truth as this feature can be
 * tested without a human listening.
 *
 *   npm run build && node tests/fixtures/speech/speech-check.mjs
 *
 * Exits non-zero on the first failed expectation.
 */
import { spawn } from 'node:child_process';
import { writeFileSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const assetDir = 'public/build/assets';
const bundle = readdirSync(assetDir)
    .filter(f => f.startsWith('article-speech-') && f.endsWith('.js'))
    .sort()
    .pop();
if (! bundle) {
    console.error('No built article-speech bundle. Run `npm run build` first.');
    process.exit(1);
}
const bundlePath = `${process.cwd()}/${assetDir}/${bundle}`;

const harness = join(tmpdir(), `speech-harness-${process.pid}.html`);
writeFileSync(harness, `<!doctype html><html><head><meta charset="utf-8"></head><body>
<div id="ctl" data-lang="ar"></div>
<article class="ca-preview">
  <h1>Best Oud Perfume</h1>
  <p>First paragraph about oud.</p>
  <p>   </p>
  <h2>What to look for</h2>
  <ul><li>Concentration matters.</li></ul>
  <figure><figcaption>A caption.</figcaption></figure>
</article>
<script>
  window.__spoken = []; window.__cancels = 0;
  window.SpeechSynthesisUtterance = function (text) { this.text = text; };
  const stub = {
    speak(u) { window.__spoken.push({ text: u.text, lang: u.lang, rate: u.rate, voice: u.voice?.name ?? null }); if (u.onstart) u.onstart(); },
    cancel() { window.__cancels++; },
    pause() {}, resume() {},
    getVoices: () => [{ name: 'Fake Arabic', lang: 'ar-SA' }, { name: 'Fake English', lang: 'en-US' }],
    addEventListener() {},
  };
  // Chrome exposes speechSynthesis as a READ-ONLY accessor on Window, so a
  // plain assignment is silently ignored and the real engine receives the
  // fake utterance. It has to be redefined.
  Object.defineProperty(window, 'speechSynthesis', { value: stub, configurable: true, writable: true });
  window.Alpine = { data: (name, fn) => { (window.__factories ||= {})[name] = fn; } };
</script>
<script type="module" src="file://${bundlePath}"></script>
<script type="module">
  await new Promise(r => setTimeout(r, 300));
  const factory = window.__factories?.articleSpeech;
  if (! factory) { window.__speechResult = { error: 'component never registered' }; }
  else {
    const c = factory();
    c.$root = document.getElementById('ctl');
    c.init();
    c.listen();
    const first = window.__spoken[0] ?? {};
    const before = window.__cancels;
    c.setRate('1.5');
    const rate = (window.__spoken.at(-1) ?? {}).rate;
    c.stop();
    window.__speechResult = {
      supported: c.supported,
      blocks: c.total,
      spoken: window.__spoken.slice(0, 5).map(s => s.text),
      lang: first.lang,
      voice: first.voice,
      rate,
      cancelled: window.__cancels > before,
      highlightCleared: document.querySelectorAll('.ca-speaking').length === 0,
    };
  }
</script>
</body></html>`);

const port = 9400 + (process.pid % 400);
const chrome = spawn('google-chrome', [
    '--headless=new', '--disable-gpu', '--no-sandbox', `--remote-debugging-port=${port}`,
    '--no-first-run', '--allow-file-access-from-files',
    `--user-data-dir=${join(tmpdir(), `cdp-speech-${process.pid}`)}`, 'about:blank',
], { stdio: 'ignore' });

const wait = ms => new Promise(r => setTimeout(r, ms));
let wsUrl = null;
for (let i = 0; i < 40 && ! wsUrl; i++) {
    await wait(250);
    try {
        const list = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
        wsUrl = list.find(t => t.type === 'page')?.webSocketDebuggerUrl;
    } catch { /* not up yet */ }
}
if (! wsUrl) { console.error('Could not start headless Chrome.'); chrome.kill(); process.exit(1); }

const ws = new WebSocket(wsUrl);
let id = 0;
const pending = new Map();
const events = [];
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    if (m.method) events.push(m.method);
};
const send = (method, params = {}) => new Promise(res => {
    const mid = ++id; pending.set(mid, res);
    ws.send(JSON.stringify({ id: mid, method, params }));
});
await new Promise(r => ws.onopen = r);
await send('Page.enable');
await send('Page.navigate', { url: `file://${harness}` });
for (let i = 0; i < 60 && ! events.includes('Page.loadEventFired'); i++) await wait(250);
await wait(600);

const raw = await send('Runtime.evaluate', {
    expression: 'JSON.stringify(window.__speechResult ?? {error: "harness did not run"})',
});
ws.close(); chrome.kill();

const got = JSON.parse(raw.result.result.value);
const failures = [];
const expect = (label, actual, wanted) => {
    const ok = JSON.stringify(actual) === JSON.stringify(wanted);
    console.log(`${ok ? 'ok  ' : 'FAIL'}  ${label}: ${JSON.stringify(actual)}`);
    if (! ok) failures.push(`${label} — wanted ${JSON.stringify(wanted)}`);
};

expect('speech is supported', got.supported, true);
// The empty <p> is skipped and the <ul> is not read on top of its <li>.
expect('blocks read', got.blocks, 5);
expect('read in document order', got.spoken, [
    'Best Oud Perfume', 'First paragraph about oud.', 'What to look for',
    'Concentration matters.', 'A caption.',
]);
expect('speaks the article language', got.lang, 'ar');
expect('picks a matching voice', got.voice, 'Fake Arabic');
expect('speed change applies', got.rate, 1.5);
expect('stop cancels the engine', got.cancelled, true);
expect('stop clears the highlight', got.highlightCleared, true);

if (failures.length) {
    console.error(`\n${failures.length} failed:\n  ${failures.join('\n  ')}`);
    process.exit(1);
}
console.log('\nAll speech checks passed.');
