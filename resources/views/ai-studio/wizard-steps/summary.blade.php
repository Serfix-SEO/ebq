{{-- Step 5 — Summary. Read-only recap of every input, then Generate. --}}
<div x-show="surface() === 'summary'" class="space-y-5">
    <div>
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Review &amp; generate</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">A quick recap of every wizard input. Click any "edit" to jump back and adjust before generating.</p>
    </div>

    <template x-if="hasGeneratedHtml()">
        <div class="flex flex-col gap-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-indigo-500/30 dark:bg-indigo-500/10">
            <div class="text-indigo-900 dark:text-indigo-200">
                <strong class="block">You already generated an article for this project.</strong>
                <span class="text-xs">Regenerating below will replace it. Open the existing draft to review or save.</span>
            </div>
            <button type="button" @click="postGenView = 'review'" class="rounded-md border border-indigo-300 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-300">Open existing article →</button>
        </div>
    </template>

    <div class="space-y-3">
        {{-- Topic --}}
        <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="mb-2 flex items-center justify-between"><h4 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Topic</h4><button type="button" @click="goToStep('topic')" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">edit</button></div>
            <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-[11px] text-slate-400">Project title</dt><dd class="font-medium text-slate-800 dark:text-slate-100" x-text="project?.title || 'Untitled'"></dd></div>
                <div><dt class="text-[11px] text-slate-400">Focused keyword</dt><dd class="font-mono text-xs text-slate-700 dark:text-slate-200" x-text="project?.focus_keyword || '—'"></dd></div>
                <div class="sm:col-span-2"><dt class="text-[11px] text-slate-400">Additional keywords</dt><dd class="text-slate-700 dark:text-slate-200" x-text="additionalKeywords().length ? additionalKeywords().join(', ') : 'none'"></dd></div>
                <div><dt class="text-[11px] text-slate-400">Country / Language</dt><dd class="text-slate-700 dark:text-slate-200"><span x-text="(project?.country || 'us').toUpperCase()"></span> / <span x-text="(project?.language || 'en').toUpperCase()"></span></dd></div>
                <div><dt class="text-[11px] text-slate-400">Tone / Audience</dt><dd class="text-slate-700 dark:text-slate-200"><span x-text="cap(project?.tone || 'professional')"></span> / <span x-text="cap(project?.audience || 'general')"></span></dd></div>
                <template x-if="(project?.custom_prompt || '').trim() !== ''">
                    <div class="sm:col-span-2"><dt class="text-[11px] text-slate-400">Custom instructions</dt><dd class="rounded bg-slate-50 p-2 text-xs italic text-slate-600 dark:bg-slate-800/60 dark:text-slate-300" x-text="truncate(project.custom_prompt, 280)"></dd></div>
                </template>
            </dl>
        </section>

        {{-- Brief --}}
        <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="mb-2 flex items-center justify-between"><h4 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Content brief</h4><button type="button" @click="goToStep('brief')" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">edit</button></div>
            <div class="flex flex-wrap gap-1.5 text-[11px]">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300"><span x-text="summarySections().length"></span> sections</span>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300"><span x-text="summaryPaa().length"></span> PAA</span>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300"><span x-text="summaryGaps().length"></span> gaps</span>
            </div>
            <ol class="mt-2 list-decimal space-y-0.5 pl-5 text-sm text-slate-700 dark:text-slate-200" x-show="summarySections().length > 0">
                <template x-for="(s, i) in summarySections().slice(0, 8)" :key="i"><li x-text="s.h2"></li></template>
                <li x-show="summarySections().length > 8" class="list-none text-xs text-slate-400" x-text="'+ ' + (summarySections().length - 8) + ' more'"></li>
            </ol>
        </section>

        {{-- Strategy --}}
        <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="mb-2 flex items-center justify-between"><h4 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Strategy</h4><button type="button" @click="goToStep('strategy')" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">edit</button></div>
            <dl class="space-y-2 text-sm">
                <div><dt class="text-[11px] text-slate-400">SEO title</dt><dd class="text-slate-700 dark:text-slate-200" x-text="project?.meta_title || (suggestionsFor('seo_titles')[0] || 'none yet')"></dd></div>
                <div><dt class="text-[11px] text-slate-400">Meta description</dt><dd class="text-slate-700 dark:text-slate-200" x-text="project?.meta_description || (suggestionsFor('meta_descriptions')[0] || 'none yet')"></dd></div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div><dt class="text-[11px] text-slate-400">FAQs</dt><dd class="font-semibold text-slate-700 dark:text-slate-200" x-text="strategyFaqs().length"></dd></div>
                    <div><dt class="text-[11px] text-slate-400">Keyword sugg.</dt><dd class="font-semibold text-slate-700 dark:text-slate-200" x-text="keywordSuggestions().length"></dd></div>
                    <div><dt class="text-[11px] text-slate-400">Internal links</dt><dd class="font-semibold text-slate-700 dark:text-slate-200" x-text="selectedLinks().internal.length"></dd></div>
                    <div><dt class="text-[11px] text-slate-400">External links</dt><dd class="font-semibold text-slate-700 dark:text-slate-200" x-text="selectedLinks().external.length"></dd></div>
                </div>
            </dl>
        </section>

        {{-- Images --}}
        <section class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="mb-2 flex items-center justify-between"><h4 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Images</h4><button type="button" @click="goToStep('images')" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">edit</button></div>
            <p x-show="im.selected.length === 0" class="text-xs italic text-slate-400">No images selected. The article will publish without inline media.</p>
            <div x-show="im.selected.length > 0" class="flex flex-wrap gap-2">
                <template x-for="(img, i) in im.selected" :key="i">
                    <figure class="w-24"><img :src="img.thumbnail_url || img.url" :alt="img.alt || ''" class="h-16 w-24 rounded object-cover"><figcaption x-show="img.assigned_h2" class="mt-0.5 truncate text-[10px] text-slate-400" x-text="img.assigned_h2"></figcaption></figure>
                </template>
            </div>
        </section>
    </div>

    <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
        Estimated EBQ Content Credits for this generation: <strong x-text="'~' + estCredits()"></strong>
    </div>

    <template x-if="error"><div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="error"></div></template>

    <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
        <button type="button" @click="goToStep('images')" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400">← Back</button>
        <button type="button" @click="generateArticle()" :disabled="loading || summarySections().length === 0 || featureLocked"
            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 dark:disabled:bg-slate-700"
            x-text="loading ? 'Generating article…' : (hasGeneratedHtml() ? 'Regenerate article →' : 'Generate article →')"></button>
    </div>
</div>
