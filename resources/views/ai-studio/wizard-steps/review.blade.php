{{-- Step 6 — Review. Preview / edit the generated HTML, then copy,
     download, or mark complete. The WP plugin's "save as WP draft"
     (wp/v2/posts) + TinyMCE have no dashboard analogue — the platform
     has no outbound WordPress credentials — so the dashboard offers
     copy / download / mark-complete instead. The HTML is already
     persisted on the project. --}}
<div x-show="surface() === 'review'" class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Your article is ready</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Preview below, switch to Edit for inline tweaks, then copy the HTML or download it.</p>
        </div>
        <button type="button" @click="postGenView = 'wizard'; goToStep('summary')" class="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">← Back to summary</button>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-xs font-semibold dark:border-slate-700 dark:bg-slate-900">
            <button type="button" @click="rv.mode = 'preview'" class="rounded-md px-3 py-1.5 transition" :class="rv.mode === 'preview' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'">Preview</button>
            <button type="button" @click="rv.mode = 'edit'" class="rounded-md px-3 py-1.5 transition" :class="rv.mode === 'edit' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'">Edit HTML</button>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="reviewRegenerate()" :disabled="rv.regenerating || rv.saving" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" x-text="rv.regenerating ? 'Regenerating…' : 'Regenerate'"></button>
            <button type="button" @click="reviewCopy()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" x-text="rv.copied ? 'Copied ✓' : 'Copy HTML'"></button>
            <button type="button" @click="reviewDownload()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Download .html</button>
            <button type="button" @click="reviewMarkComplete()" :disabled="rv.saving || !(rv.html || '').trim()" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" x-text="rv.saving ? 'Saving…' : (project?.step === 'completed' ? 'Completed ✓' : 'Mark complete')"></button>
        </div>
    </div>

    {{-- Stats --}}
    <ul class="flex flex-wrap gap-4 rounded-lg bg-slate-50 px-4 py-2 text-sm dark:bg-slate-800/60">
        <li><strong x-text="reviewStats().words.toLocaleString()"></strong> <span class="text-slate-500 dark:text-slate-400">words</span></li>
        <li><strong x-text="reviewStats().headings"></strong> <span class="text-slate-500 dark:text-slate-400">headings</span></li>
        <li><strong x-text="reviewStats().paragraphs"></strong> <span class="text-slate-500 dark:text-slate-400">paragraphs</span></li>
        <li><strong x-text="reviewStats().images"></strong> <span class="text-slate-500 dark:text-slate-400">images</span></li>
        <li><strong x-text="reviewStats().links"></strong> <span class="text-slate-500 dark:text-slate-400">links</span></li>
        <li><strong x-text="'~' + reviewStats().minutes"></strong> <span class="text-slate-500 dark:text-slate-400">min read</span></li>
    </ul>

    {{-- Preview / edit --}}
    <div x-show="rv.mode === 'preview'" class="prose prose-sm max-w-none rounded-xl border border-slate-200 bg-white p-5 dark:prose-invert dark:border-slate-800 dark:bg-slate-900" x-html="previewHtml()"></div>
    <div x-show="rv.mode === 'edit'">
        <textarea x-model="rv.html" rows="22" spellcheck="false" placeholder="Generated content will appear here."
            class="w-full rounded-xl border border-slate-200 bg-white p-4 font-mono text-xs leading-relaxed text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100"></textarea>
        <p class="mt-1 text-[11px] text-slate-400">Edits stay local to this preview. Use Copy / Download to take the HTML, or Regenerate to rebuild from the brief.</p>
    </div>

    <template x-if="rv.error"><div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="rv.error"></div></template>
    <template x-if="rv.saved"><div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">Project marked complete. The generated HTML is saved on the project — copy or download it above to publish.</div></template>
</div>
