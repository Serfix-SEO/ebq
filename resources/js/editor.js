/*
 * Content Autopilot — full WYSIWYG article editor (TipTap / ProseMirror).
 *
 * Replaces the old contenteditable + document.execCommand surface in the
 * article Review screen. Registered as an Alpine.data component ("tiptapEditor")
 * against the Alpine instance Livewire injects (never Alpine.start()ed here) —
 * so it must hook `alpine:init`, which Livewire fires before starting Alpine.
 *
 * Loaded as an isolated Vite entrypoint (only shipped on the review route via
 * an @assets block), so TipTap's ~50-70 KB never rides onto every page.
 *
 * Server contract is UNCHANGED: body still round-trips as an HTML string —
 * onUpdate → $wire.rescore(getHTML()), Save → $wire.saveEdits(getHTML()),
 * selection AI → $wire.aiEdit(tool,text,tone). Images: upload via
 * $wire.upload()+$wire.uploadInlineImage(), URL insert client-side, AI-generate
 * via $wire.requestInlineImage()+poll $wire.pollInlineImage().
 */
import { Editor, Node, mergeAttributes } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import TextAlign from '@tiptap/extension-text-align'
import Placeholder from '@tiptap/extension-placeholder'
import { Table } from '@tiptap/extension-table'
import { TableRow } from '@tiptap/extension-table-row'
import { TableHeader } from '@tiptap/extension-table-header'
import { TableCell } from '@tiptap/extension-table-cell'

/*
 * Custom block node that serialises to the pipeline's exact image markup —
 * <figure class="content-image"><img src alt loading="lazy"></figure> — so
 * editor-inserted images match GenerateContentImagesJob's figures, round-trip
 * on re-open (parseHTML reads existing figures), and keep the .content-image
 * styling + the WP sideload convention. The stock Image node would emit a bare
 * <img> and break all three.
 */
const ContentFigure = Node.create({
    name: 'contentFigure',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            src: { default: null },
            alt: { default: '' },
        }
    },

    parseHTML() {
        return [
            {
                tag: 'figure.content-image',
                getAttrs: (el) => {
                    const img = el.querySelector('img')
                    if (!img) return false
                    return { src: img.getAttribute('src'), alt: img.getAttribute('alt') || '' }
                },
            },
            // Also absorb a bare <img> (e.g. pasted) into a content figure.
            {
                tag: 'img[src]',
                getAttrs: (el) => ({ src: el.getAttribute('src'), alt: el.getAttribute('alt') || '' }),
            },
        ]
    },

    renderHTML({ HTMLAttributes }) {
        const { src, alt } = HTMLAttributes
        return ['figure', { class: 'content-image' }, ['img', mergeAttributes({ loading: 'lazy' }, { src, alt })]]
    },

    addCommands() {
        return {
            setContentFigure:
                (attrs) =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name, attrs }),
        }
    },
})

function buildEditor(mount, initialHtml, onUpdate, onSelection, placeholder) {
    return new Editor({
        element: mount,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3, 4] },
                link: { openOnClick: false, autolink: true, HTMLAttributes: { rel: 'noopener' } },
            }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            ContentFigure,
            Table.configure({ resizable: true, HTMLAttributes: { class: 'ca-table' } }),
            TableRow,
            TableHeader,
            TableCell,
            Placeholder.configure({ placeholder: placeholder || '' }),
        ],
        content: initialHtml || '<p></p>',
        editorProps: {
            attributes: {
                class: 'ca-preview prose prose-slate max-w-none focus:outline-none dark:prose-invert',
                spellcheck: 'true',
            },
        },
        onUpdate,
        onSelectionUpdate: onSelection,
    })
}

function registerTiptap() {
    if (!window.Alpine || window.Alpine.__tiptapRegistered) return
    window.Alpine.__tiptapRegistered = true

    window.Alpine.data('tiptapEditor', (initialHtml = '', i18n = {}) => {
        // CRITICAL: the TipTap Editor is held in a CLOSURE variable, never in the
        // returned (reactive) component data. Alpine deep-proxies component data
        // via Vue reactivity; proxying a ProseMirror Editor breaks its `view`
        // getter ("Cannot access view['__v_isRef']"), so the doc holds content but
        // the view never attaches to the DOM. Keeping it out of reactivity is the
        // fix. Only plain UI flags below stay reactive (busy/menu/selTick).
        let editor = null
        // The editor's OWN serialization of the initial content. TipTap parses
        // the stored HTML into its schema then re-serializes on getHTML(), which
        // is not byte-identical to the stored HTML (headings/figure/table nodes
        // normalize). Captured right after mount so we can ignore the no-op
        // onUpdate TipTap emits while normalizing on load — otherwise it would
        // rescore the identical baseline and make the score visibly change the
        // instant the editor mounts, before any real edit (prod 2026-07-28).
        let baselineHtml = null
        const debounce = { t: null }

        return {
            busy: false,
            genBusy: false,
            notice: '',
            // AI selection menu
            menuOpen: false,
            toneOpen: false,
            menuX: 0,
            menuY: 0,
            aiFrom: 0,
            aiTo: 0,
            // reactive tick so toolbar active-states re-render on selection change
            selTick: 0,
            i18n,

            // NB: deliberately NOT named init() — Alpine auto-invokes an init()
            // method with no args, which would build a detached editor (mount
            // undefined) and then the x-init call would be guarded out. Named
            // mountEditor() and called only from x-init with the real $refs.mount.
            mountEditor(mount) {
                if (editor) return // guard double-mount under wire:ignore
                editor = buildEditor(
                    mount,
                    initialHtml,
                    () => {
                        this.selTick++
                        clearTimeout(debounce.t)
                        debounce.t = setTimeout(() => {
                            if (!editor) return
                            const html = editor.getHTML()
                            // Ignore the initial normalization update — only rescore
                            // once the content genuinely differs from the editor's own
                            // baseline, so opening the editor never moves the score.
                            if (html === baselineHtml) return
                            this.$wire.rescore(html)
                        }, 800)
                    },
                    () => {
                        this.selTick++
                        this.updateSelectionMenu()
                    },
                    this.i18n.placeholder || '',
                )
                // Baseline = the editor's OWN serialization of the initial content,
                // so the load-time normalization update is recognised as a no-op and
                // never rescored (keeps the score stable until a real edit).
                baselineHtml = editor.getHTML()
                // Tear down cleanly if Livewire ever removes the node.
                this.$el.addEventListener('livewire:navigating', () => this.destroy())
            },

            destroy() {
                if (editor) {
                    editor.destroy()
                    editor = null
                }
            },

            // ── toolbar ──────────────────────────────────────────────────
            cmd(name, ...args) {
                if (!editor) return
                editor.chain().focus()[name](...args).run()
            },

            isActive(name, attrs = {}) {
                // eslint-disable-next-line no-unused-expressions
                this.selTick // reactive dependency
                return !!editor && editor.isActive(name, attrs)
            },

            activeCls(name, attrs = {}) {
                return this.isActive(name, attrs)
                    ? 'bg-orange-100 text-orange-700 dark:bg-slate-800 dark:text-orange-300'
                    : ''
            },

            toggleLink() {
                if (!editor) return
                const prev = editor.getAttributes('link').href || ''
                const url = window.prompt(this.i18n.linkUrl || 'Link URL', prev)
                if (url === null) return
                if (url.trim() === '') {
                    editor.chain().focus().extendMarkRange('link').unsetLink().run()
                    return
                }
                editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run()
            },

            insertTable() {
                this.cmd('insertTable', { rows: 3, cols: 3, withHeaderRow: true })
            },

            clearFormat() {
                if (!editor) return
                editor.chain().focus().unsetAllMarks().clearNodes().run()
            },

            // ── images ───────────────────────────────────────────────────
            pickImage() {
                this.$refs.file && this.$refs.file.click()
            },

            uploadImage(file) {
                if (!file || !editor) return
                this.busy = true
                this.$wire.upload(
                    'inlineImage',
                    file,
                    async () => {
                        try {
                            const res = await this.$wire.uploadInlineImage()
                            if (res && res.url) {
                                const alt = window.prompt(this.i18n.altPrompt || 'Describe this image (alt text)', '') || ''
                                editor.chain().focus().setContentFigure({ src: res.url, alt }).run()
                                this.$wire.rescore(editor.getHTML())
                            }
                        } finally {
                            this.busy = false
                            if (this.$refs.file) this.$refs.file.value = ''
                        }
                    },
                    () => {
                        this.busy = false
                        if (this.$refs.file) this.$refs.file.value = ''
                    },
                )
            },

            insertImageUrl() {
                if (!editor) return
                const url = window.prompt(this.i18n.imageUrl || 'Image URL', '')
                if (!url || url.trim() === '') return
                const alt = window.prompt(this.i18n.altPrompt || 'Describe this image (alt text)', '') || ''
                editor.chain().focus().setContentFigure({ src: url.trim(), alt }).run()
                this.$wire.rescore(editor.getHTML())
            },

            async generateImage() {
                if (!editor || this.genBusy) return
                const prompt = window.prompt(this.i18n.genPrompt || 'Describe the image to generate', '')
                if (!prompt || prompt.trim() === '') return
                this.genBusy = true
                try {
                    const id = await this.$wire.requestInlineImage(prompt.trim())
                    if (!id) {
                        this.genBusy = false
                        return // server flashed a friendly notice (disabled / cap hit)
                    }
                    // Poll for the async job to finish (~2s cadence, bounded ~90s).
                    const started = Date.now()
                    const poll = async () => {
                        if (Date.now() - started > 90000) {
                            this.genBusy = false
                            window.alert(this.i18n.genTimeout || 'Image is taking longer than expected — check back shortly.')
                            return
                        }
                        const res = await this.$wire.pollInlineImage(id)
                        if (res && res.url) {
                            const alt = window.prompt(this.i18n.altPrompt || 'Describe this image (alt text)', prompt.trim()) || prompt.trim()
                            editor.chain().focus().setContentFigure({ src: res.url, alt }).run()
                            this.$wire.rescore(editor.getHTML())
                            this.genBusy = false
                            return
                        }
                        if (res && res.failed) {
                            this.genBusy = false
                            window.alert(this.i18n.genFailed || 'Image generation did not complete. Try again.')
                            return
                        }
                        setTimeout(poll, 2000)
                    }
                    setTimeout(poll, 2000)
                } catch (e) {
                    this.genBusy = false
                }
            },

            // ── save / cancel ─────────────────────────────────────────────
            save() {
                if (editor) this.$wire.saveEdits(editor.getHTML())
            },

            // ── selection AI menu ─────────────────────────────────────────
            updateSelectionMenu() {
                if (!editor) return
                const { from, to, empty } = editor.state.selection
                const text = editor.state.doc.textBetween(from, to, ' ')
                if (empty || text.trim().length < 3) {
                    if (!this.toneOpen) this.menuOpen = false
                    return
                }
                this.aiFrom = from
                this.aiTo = to
                try {
                    const start = editor.view.coordsAtPos(from)
                    const host = this.$el.getBoundingClientRect()
                    this.menuX = Math.max(0, start.left - host.left)
                    this.menuY = Math.max(0, start.top - host.top - 44)
                    this.menuOpen = true
                } catch (e) {
                    this.menuOpen = false
                }
            },

            async ai(tool, tone = null) {
                if (!editor || this.busy) return
                const text = editor.state.doc.textBetween(this.aiFrom, this.aiTo, ' ')
                if (text.trim() === '') return
                this.busy = true
                this.toneOpen = false
                // Keep the menu OPEN — it swaps to an in-place spinner while the
                // request runs, so the loading state is right where the user clicked
                // (the top-bar spinner is easy to miss). Closed in finally.
                try {
                    const out = await this.$wire.aiEdit(tool, text, tone)
                    if (out) {
                        editor.chain().focus().insertContentAt({ from: this.aiFrom, to: this.aiTo }, out).run()
                        this.$wire.rescore(editor.getHTML())
                    } else {
                        // No text back → surface it instead of silently doing nothing.
                        this.notice = this.i18n.aiFailed || 'The AI edit did not complete. Try again.'
                        setTimeout(() => { this.notice = '' }, 5000)
                    }
                } catch (e) {
                    this.notice = this.i18n.aiFailed || 'The AI edit did not complete. Try again.'
                    setTimeout(() => { this.notice = '' }, 5000)
                } finally {
                    this.busy = false
                    this.menuOpen = false
                }
            },
        }
    })
}

// Re-initialize any x-data="tiptapEditor(...)" element that Alpine already
// walked (and failed on) before this module registered the component. Needed
// because Livewire loads Alpine via a CLASSIC script that starts Alpine during
// page parse — BEFORE this deferred ES module executes — so `alpine:init` can
// fire before our listener is attached, leaving the element with an undefined
// `tiptapEditor` factory ("tiptapEditor is not defined"). destroyTree clears
// the failed partial state; initTree re-runs it with the now-registered factory.
function reinitTiptapEls() {
    if (!window.Alpine || typeof window.Alpine.initTree !== 'function') return
    document.querySelectorAll('[x-data]').forEach((el) => {
        if (!(el.getAttribute('x-data') || '').trim().startsWith('tiptapEditor')) return
        if (el.__tiptapReinit) return
        el.__tiptapReinit = true
        try { window.Alpine.destroyTree(el) } catch (e) { /* not yet inited — fine */ }
        try { window.Alpine.initTree(el) } catch (e) { /* Alpine will walk it itself */ }
    })
}

// Register on every plausible hook (idempotent via __tiptapRegistered).
document.addEventListener('alpine:init', registerTiptap)
document.addEventListener('livewire:init', registerTiptap)
document.addEventListener('livewire:navigated', () => {
    registerTiptap()
    reinitTiptapEls()
})

// If Alpine is ALREADY present when this deferred module finally executes, then
// alpine:init already fired and Alpine already tried (and failed) to init the
// editor element — register now and re-init those elements.
if (window.Alpine) {
    registerTiptap()
    reinitTiptapEls()
}
