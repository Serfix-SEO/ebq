/**
 * "Listen" — reads a finished article aloud using the browser's own speech
 * engine (Web Speech API). Nothing is sent anywhere: no provider, no API key,
 * no audio file, no cost. It is a review aid, so a client can proofread their
 * article by ear before approving it.
 *
 * The words come from the rendered preview in the DOM rather than from the
 * server, so what is spoken is always exactly what is on screen — including
 * after an edit.
 *
 * Registered as an Alpine component; Alpine ships with Livewire here.
 */

/** Blocks worth reading, in document order. Images and code are skipped. */
const BLOCK_SELECTOR = 'p, h1, h2, h3, h4, li, blockquote, figcaption, th, td';

/** Chrome stops speaking long runs unless poked; harmless elsewhere. */
const KEEPALIVE_MS = 10000;

export function articleSpeech() {
    return {
        supported: false,
        speaking: false,
        paused: false,
        index: 0,
        total: 0,
        rate: 1,
        blocks: [],
        keepalive: null,

        init() {
            this.supported = typeof window !== 'undefined'
                && 'speechSynthesis' in window
                && typeof window.SpeechSynthesisUtterance === 'function';
            if (! this.supported) {
                return;
            }

            // getVoices() is empty until the engine has loaded them, and the
            // event never fires in some browsers where they are ready up
            // front — so ask now AND listen.
            this.warmVoices();
            window.speechSynthesis.addEventListener?.('voiceschanged', () => this.warmVoices());

            // The speech engine belongs to the TAB, not to this element: it
            // keeps talking happily after the article has gone. Every exit
            // has to silence it.
            this.onNavigate = () => this.stop();
            document.addEventListener('livewire:navigated', this.onNavigate);
            window.addEventListener('beforeunload', this.onNavigate);
            window.addEventListener('pagehide', this.onNavigate);
        },

        destroy() {
            this.stop();
            document.removeEventListener('livewire:navigated', this.onNavigate);
            window.removeEventListener('beforeunload', this.onNavigate);
            window.removeEventListener('pagehide', this.onNavigate);
        },

        warmVoices() {
            try {
                this.voices = window.speechSynthesis.getVoices() || [];
            } catch {
                this.voices = [];
            }
        },

        /**
         * A voice that speaks the article's language. Matching on the prefix
         * ("ar" matches "ar-AE") keeps regional variants usable; with no
         * match we say nothing about it and let the browser pick.
         */
        pickVoice() {
            const want = (this.$root.dataset.lang || 'en').toLowerCase().split(/[-_]/)[0];
            if (! this.voices?.length) {
                this.warmVoices();
            }

            return (this.voices || []).find(v => (v.lang || '').toLowerCase().split(/[-_]/)[0] === want) || null;
        },

        /** Readable blocks of the article, skipping empties. */
        collect() {
            // `article.ca-preview` on purpose: the TipTap mount carries the
            // SAME ca-preview class (editor.js), so a bare '.ca-preview' would
            // read the editor surface instead of the article.
            const article = document.querySelector(this.$root.dataset.target || 'article.ca-preview');
            if (! article) {
                return [];
            }

            return Array.from(article.querySelectorAll(BLOCK_SELECTOR))
                // A <li> inside a <p> would otherwise be read twice.
                .filter(el => ! el.querySelector(BLOCK_SELECTOR))
                .map(el => ({ el, text: (el.textContent || '').replace(/\s+/g, ' ').trim() }))
                .filter(block => block.text !== '');
        },

        listen() {
            if (! this.supported) {
                return;
            }
            this.blocks = this.collect();
            this.total = this.blocks.length;
            if (this.total === 0) {
                return;
            }
            this.index = 0;
            this.speakFrom(0);
        },

        /**
         * One utterance per block, not one for the whole article. Chrome cuts
         * long utterances off after about fifteen seconds, and speaking in
         * blocks also gives progress, the highlight, and a resume point that
         * survives Safari's unreliable pause().
         */
        speakFrom(start) {
            window.speechSynthesis.cancel();
            this.speaking = true;
            this.paused = false;

            const voice = this.pickVoice();
            const lang = this.$root.dataset.lang || 'en';

            this.blocks.slice(start).forEach((block, offset) => {
                const utterance = new SpeechSynthesisUtterance(block.text);
                utterance.rate = Number(this.rate) || 1;
                utterance.lang = lang;
                if (voice) {
                    utterance.voice = voice;
                }
                utterance.onstart = () => {
                    this.index = start + offset;
                    this.highlight(block.el);
                };
                if (start + offset === this.blocks.length - 1) {
                    utterance.onend = () => this.stop();
                }
                window.speechSynthesis.speak(utterance);
            });

            this.startKeepalive();
        },

        /**
         * Pause is implemented as cancel-and-remember rather than
         * speechSynthesis.pause(), which is unreliable in Safari — resume
         * simply speaks again from the block we had reached. Correct in every
         * browser, and no user-agent sniffing.
         */
        pause() {
            if (! this.speaking) {
                return;
            }
            window.speechSynthesis.cancel();
            this.stopKeepalive();
            this.speaking = false;
            this.paused = true;
        },

        resume() {
            if (! this.paused) {
                return;
            }
            this.speakFrom(this.index);
        },

        stop() {
            try {
                window.speechSynthesis?.cancel();
            } catch {
                // Nothing to cancel.
            }
            this.stopKeepalive();
            this.speaking = false;
            this.paused = false;
            this.index = 0;
            this.clearHighlight();
        },

        /** Speed applies from the current block onward. */
        setRate(rate) {
            this.rate = Number(rate) || 1;
            if (this.speaking) {
                this.speakFrom(this.index);
            }
        },

        highlight(el) {
            this.clearHighlight();
            el.classList.add('ca-speaking');
            const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
            el.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
        },

        clearHighlight() {
            document.querySelectorAll('.ca-speaking').forEach(el => el.classList.remove('ca-speaking'));
        },

        startKeepalive() {
            this.stopKeepalive();
            this.keepalive = setInterval(() => {
                if (! this.speaking) {
                    return;
                }
                // Poking a live queue is what keeps Chrome talking.
                window.speechSynthesis.pause();
                window.speechSynthesis.resume();
            }, KEEPALIVE_MS);
        },

        stopKeepalive() {
            if (this.keepalive) {
                clearInterval(this.keepalive);
                this.keepalive = null;
            }
        },
    };
}

function registerSpeech() {
    if (! window.Alpine || window.Alpine.__articleSpeechRegistered) {
        return;
    }
    window.Alpine.__articleSpeechRegistered = true;
    window.Alpine.data('articleSpeech', articleSpeech);
}

/**
 * Re-init any x-data="articleSpeech" element Alpine already walked (and failed
 * on) before this module registered the component. Livewire loads Alpine via a
 * CLASSIC script that starts during page parse — before this deferred ES
 * module runs — so `alpine:init` can fire before our listener exists, leaving
 * the element with an undefined factory. Same fix as editor.js.
 */
function reinitSpeechEls() {
    if (! window.Alpine || typeof window.Alpine.initTree !== 'function') {
        return;
    }
    document.querySelectorAll('[x-data]').forEach((el) => {
        if (! (el.getAttribute('x-data') || '').trim().startsWith('articleSpeech')) {
            return;
        }
        if (el.__speechReinit) {
            return;
        }
        el.__speechReinit = true;
        try { window.Alpine.destroyTree(el); } catch { /* not yet inited — fine */ }
        try { window.Alpine.initTree(el); } catch { /* Alpine will walk it itself */ }
    });
}

document.addEventListener('alpine:init', registerSpeech);
document.addEventListener('livewire:init', registerSpeech);
document.addEventListener('livewire:navigated', () => {
    registerSpeech();
    reinitSpeechEls();
});

if (window.Alpine) {
    registerSpeech();
    reinitSpeechEls();
}
