{{-- Step 1 — Topic. Focus keyword, extra keywords, locale/voice, custom prompt. --}}
<div x-show="surface() === 'topic'" class="space-y-5">
    <div>
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Tell us what you want to write about</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">We'll pull a content brief from the live SERP and let you shape headings before generation.</p>
    </div>

    <div class="grid grid-cols-1 gap-4">
        <label class="block">
            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Project title</span>
            <input type="text" x-model="t.title" placeholder="Optional — we'll suggest one from the focus keyword"
                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        </label>

        <label class="block">
            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Focused keyword <span class="text-red-500">*</span></span>
            <input type="text" x-model="t.focusKw" placeholder="e.g. vegan protein powder"
                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        </label>

        <label class="block">
            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Additional keywords</span>
            <input type="text" x-model="t.additionalRaw" placeholder="comma-separated, e.g. amino acids, muscle gain"
                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <div class="mt-1.5 flex flex-wrap gap-1" x-show="topicAdditionalKws().length > 0">
                <template x-for="(k, i) in topicAdditionalKws()" :key="i + k">
                    <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300" x-text="k"></span>
                </template>
            </div>
        </label>

        <label class="block">
            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">LSI keywords</span>
            <input type="text" x-model="t.lsiRaw" placeholder="comma-separated, e.g. plant-based protein, complete amino profile"
                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <div class="mt-1.5 flex flex-wrap gap-1" x-show="topicLsiKws().length > 0">
                <template x-for="(k, i) in topicLsiKws()" :key="i + k">
                    <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300" x-text="k"></span>
                </template>
            </div>
            <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Semantically-related terms used to broaden topical coverage. The writer weaves them in where they fit.</p>
        </label>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Country (SEO target)</span>
                <select x-model="t.country" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <template x-for="c in COUNTRIES" :key="c[0]"><option :value="c[0]" x-text="c[1]"></option></template>
                </select>
            </label>
            <label class="block">
                <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Language</span>
                <select x-model="t.language" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <template x-for="l in LANGUAGES" :key="l[0]"><option :value="l[0]" x-text="l[1]"></option></template>
                </select>
            </label>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Tone</span>
                <select x-model="t.tone" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <template x-for="tn in TONES" :key="tn"><option :value="tn" x-text="cap(tn)"></option></template>
                </select>
            </label>
            <label class="block">
                <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Audience</span>
                <select x-model="t.audience" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <template x-for="a in AUDIENCES" :key="a[0]"><option :value="a[0]" x-text="a[1]"></option></template>
                </select>
            </label>
        </div>

        <div class="block">
            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Custom writing instructions (optional)</span>
            <select x-show="prompts.length > 0" x-model="t.selectedPromptId" @change="onPromptPick()"
                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                <option value="">— New prompt —</option>
                <template x-for="p in prompts" :key="p.id"><option :value="p.id" x-text="p.title"></option></template>
            </select>
            <textarea x-model="t.customPrompt" @input="onPromptType()" rows="4" maxlength="2000"
                placeholder="e.g. Write in second person, keep paragraphs to 2–3 sentences, weave in a personal anecdote near the intro."
                class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
            <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Added on top of EBQ's built-in writing instructions. Off-topic prompts are rejected.</p>
        </div>
    </div>

    <template x-if="hasExistingBrief() && briefInputsUnchanged()">
        <p class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">We saved your brief from before — your headings, edits, and chat history are still there. Continue to keep working, or regenerate to pull a fresh outline.</p>
    </template>

    <template x-if="t.error">
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="t.error"></div>
    </template>

    <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
        <button type="button" x-show="hasExistingBrief()" @click="topicRegenerateBrief()" :disabled="!topicCanSubmit()"
            class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            x-text="(t.busy && t.busyAction === 'regenerate') ? 'Rebuilding…' : 'Regenerate brief'"></button>
        <button type="button" @click="topicSubmit()" :disabled="!topicCanSubmit() || featureLocked"
            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 dark:disabled:bg-slate-700"
            x-text="(t.busy && t.busyAction === 'continue') ? 'Working…' : (featureLocked ? 'Upgrade to use' : topicContinueLabel())"></button>
    </div>
</div>
