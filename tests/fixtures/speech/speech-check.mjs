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
import { writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Read the Vite manifest, never the directory listing: public/build keeps
// every old hashed asset on purpose (emptyOutDir is false), so picking by
// filename order silently loads a STALE bundle — which is exactly what made
// this check report failures against code that had already been fixed.
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/article-speech.js'];
if (! entry?.file) {
    console.error('article-speech is not in public/build/manifest.json. Run `npm run build` first.');
    process.exit(1);
}
const bundlePath = `${process.cwd()}/public/build/${entry.file}`;

const harness = join(tmpdir(), `speech-harness-${process.pid}.html`);
writeFileSync(harness, `<!doctype html><html><head><meta charset="utf-8"></head><body>
<div id="ctl" data-lang="ar" data-lang-label="Arabic" data-no-voice="No :language voice." data-failed="It stopped."></div>
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
  window.__voices = [{ name: 'Fake Arabic', lang: 'ar-SA' }, { name: 'Fake English', lang: 'en-US' }];
  window.__forceError = null;
  const stub = {
    speak(u) {
      window.__spoken.push({ text: u.text, lang: u.lang, rate: u.rate, voice: u.voice?.name ?? null });
      if (window.__forceError) { if (u.onerror) u.onerror({ error: window.__forceError }); return; }
      if (u.onstart) u.onstart();
    },
    cancel() { window.__cancels++; },
    pause() {}, resume() {},
    getVoices: () => window.__voices,
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
    const ctl = document.getElementById('ctl');
    const art = document.querySelector('article');

    // Each scenario is self-contained: its own article text, plan hint and
    // voice list. Sharing them let one scenario's leftovers decide the next
    // one's outcome, which produced confident nonsense.
    const run = ({ html, lang = 'ar', label = 'Arabic', voices, error = null, after = null }) => {
      art.innerHTML = html;
      ctl.dataset.lang = lang;
      ctl.dataset.langLabel = label;
      window.__voices = voices;
      window.__forceError = error;
      window.__spoken = [];
      window.__cancels = 0;
      const c = factory();
      c.$root = ctl;
      c.init();
      c.listen();
      const out = {
        lang: c.lang,
        voice: window.__spoken[0]?.voice ?? null,
        spoken: window.__spoken.map(u => u.text),
        blocks: c.total,
        problem: c.problem,
        message: c.message,
        playing: c.speaking,
      };
      if (after) after(c, out);
      c.stop();
      window.__forceError = null;

      return out;
    };

    const bothVoices = [{ name: 'Fake Arabic', lang: 'ar-SA' }, { name: 'Fake English', lang: 'en-US' }];
    const structureHtml = '<h1>Best Oud Perfume</h1><p>First paragraph about oud.</p><p>   </p>'
      + '<h2>What to look for</h2><ul><li>Concentration matters.</li></ul>'
      + '<figure><figcaption>A caption.</figcaption></figure>';

    // 1. Structure + controls. English words on an Arabic-plan site: the
    //    reported bug — the words must decide, not the site setting.
    const structure = run({
      html: structureHtml,
      voices: bothVoices,
      after: (c, out) => {
        c.setRate('1.5');
        out.rate = window.__spoken.at(-1)?.rate;
        const before = window.__cancels;
        c.stop();
        out.cancelled = window.__cancels > before;
        out.highlightCleared = document.querySelectorAll('.ca-speaking').length === 0;
      },
    });

    // 2. Arabic words on the same Arabic-plan site.
    const arabic = run({ html: '<p>أسماء فري فاير للشباب</p><p>نسخ ولصق للأسماء</p>', voices: bothVoices });

    // 3. Latin script with a Latin plan language: characters cannot tell
    //    French from English, so the plan's language must win.
    const french = run({
      html: '<p>Bonjour, ceci est un article en francais.</p>',
      lang: 'fr', label: 'French',
      voices: [{ name: 'Fake French', lang: 'fr-FR' }, { name: 'Fake English', lang: 'en-US' }],
    });

    // 4. Arabic article on a device with only an English voice.
    const noVoice = run({ html: '<p>أسماء فري فاير للشباب</p>', voices: [{ name: 'Fake English', lang: 'en-US' }] });

    // 5. The engine gives up mid-article.
    const failing = run({ html: '<p>أسماء فري فاير للشباب</p>', voices: bothVoices, error: 'synthesis-failed' });

    window.__speechResult = { structure, arabic, french, noVoice, failing, supported: true };
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
// Blocks: the empty <p> is skipped and the <ul> is not read on top of its <li>.
expect('blocks read', got.structure.blocks, 5);
expect('read in document order', got.structure.spoken, [
    'Best Oud Perfume', 'First paragraph about oud.', 'What to look for',
    'Concentration matters.', 'A caption.',
]);
expect('speed change applies', got.structure.rate, 1.5);
expect('stop cancels the engine', got.structure.cancelled, true);
expect('stop clears the highlight', got.structure.highlightCleared, true);

// The article's own words pick the voice, not the site-wide plan setting.
expect('English article on an Arabic plan reads English', [got.structure.lang, got.structure.voice], ['en', 'Fake English']);
expect('Arabic article reads Arabic', [got.arabic.lang, got.arabic.voice], ['ar', 'Fake Arabic']);
expect('French plan keeps French for Latin text', [got.french.lang, got.french.voice], ['fr', 'Fake French']);

// A device with no voice for the language: say so, do not fake playing.
expect('missing voice is reported', got.noVoice.problem, 'no-voice');
expect('missing voice names the language', got.noVoice.message, 'No Arabic voice.');
expect('missing voice speaks nothing', got.noVoice.spoken, []);
expect('missing voice does not fake playing', got.noVoice.playing, false);

// The engine refuses mid-article.
expect('engine failure is reported', got.failing.problem, 'failed');
expect('engine failure explains itself', got.failing.message, 'It stopped.');
expect('engine failure stops the player', got.failing.playing, false);

if (failures.length) {
    console.error(`\n${failures.length} failed:\n  ${failures.join('\n  ')}`);
    process.exit(1);
}
console.log('\nAll speech checks passed.');
