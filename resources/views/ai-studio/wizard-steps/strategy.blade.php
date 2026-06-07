{{-- Step 3 — Strategy. Meta tags, FAQs, keyword + link suggestions. --}}
<div x-show="surface() === 'strategy'" class="space-y-4">
    {{-- Empty / building state --}}
    <template x-if="!strategyHasAny()">
        <div class="space-y-4">
            <div class="rounded-lg bg-slate-50 p-5 text-center dark:bg-slate-800/60">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Building your strategy</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" x-text="s.busyAll ? 'Generating SEO titles, meta tags, FAQs, keyword and link suggestions. Takes 10–20 seconds.' : 'Could not auto-generate strategy. Click below to try again.'"></p>
            </div>
            <template x-if="s.error"><div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="s.error"></div></template>
            <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                <button type="button" @click="goToStep('brief')" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400">← Back</button>
                <button type="button" @click="runStrategy(null)" :disabled="s.busyAll" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60" x-text="s.busyAll ? 'Building strategy…' : 'Generate strategy'"></button>
            </div>
        </div>
    </template>

    {{-- Populated --}}
    <template x-if="strategyHasAny()">
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Strategy</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400" x-text="strategyHeadline()"></p>
                </div>
                <button type="button" @click="runStrategy(null)" :disabled="s.busyAll" class="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" x-text="s.busyAll ? 'Regenerating…' : '↻ Regenerate all'"></button>
            </div>

            {{-- ── Meta section ── --}}
            <section class="rounded-xl border border-slate-200 dark:border-slate-800">
                <header class="flex items-center justify-between px-4 py-3">
                    <button type="button" @click="toggleSec('meta')" class="flex min-w-0 items-center gap-2 text-left">
                        <span class="text-slate-400" x-text="s.open.meta ? '▾' : '▸'"></span>
                        <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Title &amp; meta tags</span>
                        <span class="hidden text-[11px] text-slate-400 sm:inline">Written into the post when you save the draft.</span>
                    </button>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="metaTone() === 'good' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'" x-text="metaTone() === 'good' ? 'In band' : 'Tune'"></span>
                        <button type="button" @click="runStrategy(['meta'])" :disabled="s.busyCard.meta" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800" title="Regenerate meta">↻</button>
                    </div>
                </header>
                <div x-show="s.open.meta" class="space-y-4 border-t border-slate-100 p-4 dark:border-slate-800">
                    {{-- meta_title --}}
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-300">
                            <span>Meta title</span>
                            <span :class="metaFieldLenClass('meta_title', 50, 60)" x-text="(s.drafts.meta_title || '').length + '/50–60'"></span>
                        </div>
                        <p class="text-[11px] text-slate-400">50–60 chars hits full SERP width.</p>
                        <input type="text" x-model="s.drafts.meta_title" @blur="metaBlur('meta_title')" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                        <template x-if="metaKwMissing('meta_title')"><p class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">⚠ Focus keyword is missing.</p></template>
                        @include('ai-studio.wizard-steps.partials.suggestions', ['field' => 'meta_title', 'key' => 'seo_titles'])
                    </div>
                    {{-- meta_description --}}
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-300">
                            <span>Meta description</span>
                            <span :class="metaFieldLenClass('meta_description', 120, 158)" x-text="(s.drafts.meta_description || '').length + '/120–158'"></span>
                        </div>
                        <p class="text-[11px] text-slate-400">120–158 chars: under pads, over truncates.</p>
                        <textarea x-model="s.drafts.meta_description" @blur="metaBlur('meta_description')" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
                        <template x-if="metaKwMissing('meta_description')"><p class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">⚠ Focus keyword is missing.</p></template>
                        @include('ai-studio.wizard-steps.partials.suggestions', ['field' => 'meta_description', 'key' => 'meta_descriptions'])
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <div class="flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-300"><span>Open Graph title</span><span class="text-slate-400" x-text="(s.drafts.og_title || '').length + '/70'"></span></div>
                            <input type="text" x-model="s.drafts.og_title" @blur="metaBlur('og_title')" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                        </div>
                        <div>
                            <div class="flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-300"><span>Open Graph description</span><span class="text-slate-400" x-text="(s.drafts.og_description || '').length + '/180'"></span></div>
                            <textarea x-model="s.drafts.og_description" @blur="metaBlur('og_description')" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── FAQs ── --}}
            <section class="rounded-xl border border-slate-200 dark:border-slate-800">
                <header class="flex items-center justify-between px-4 py-3">
                    <button type="button" @click="toggleSec('faqs')" class="flex items-center gap-2">
                        <span class="text-slate-400" x-text="s.open.faqs ? '▾' : '▸'"></span>
                        <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">FAQs</span>
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="strategyFaqs().length > 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'" x-text="strategyFaqs().length > 0 ? (strategyFaqs().length + ' generated') : 'none'"></span>
                    </button>
                    <button type="button" @click="runStrategy(['faqs'])" :disabled="s.busyCard.faqs" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800" title="Regenerate FAQs">↻</button>
                </header>
                <div x-show="s.open.faqs" class="border-t border-slate-100 p-4 dark:border-slate-800">
                    <p x-show="strategyFaqs().length === 0" class="text-xs text-slate-400">No FAQs yet. Click ↻ to regenerate.</p>
                    <ul class="space-y-2">
                        <template x-for="(f, i) in strategyFaqs()" :key="i">
                            <li class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100" x-text="f.question"></p>
                                    <button type="button" @click="removeFaq(i)" class="rounded p-1 text-slate-400 hover:text-red-500" title="Remove">✕</button>
                                </div>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300" x-text="f.answer"></p>
                            </li>
                        </template>
                    </ul>
                </div>
            </section>

            {{-- ── Keywords ── --}}
            <section class="rounded-xl border border-slate-200 dark:border-slate-800">
                <header class="flex items-center justify-between px-4 py-3">
                    <button type="button" @click="toggleSec('keywords')" class="flex items-center gap-2">
                        <span class="text-slate-400" x-text="s.open.keywords ? '▾' : '▸'"></span>
                        <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Related keywords</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800" x-text="keywordSuggestions().length + ' suggested'"></span>
                    </button>
                    <button type="button" @click="runStrategy(['keyword_suggestions'])" :disabled="s.busyCard.keyword_suggestions" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800" title="Regenerate keywords">↻</button>
                </header>
                <div x-show="s.open.keywords" class="border-t border-slate-100 p-4 dark:border-slate-800">
                    <p x-show="keywordSuggestions().length === 0" class="text-xs text-slate-400">No suggestions yet. Click ↻ to regenerate.</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="kw in keywordSuggestions()" :key="kw">
                            <button type="button" @click="addKeyword(kw)" :disabled="additionalKeywords().includes(kw)"
                                class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold transition"
                                :class="additionalKeywords().includes(kw) ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'">
                                <span x-text="kw"></span>
                                <span x-text="additionalKeywords().includes(kw) ? '✓' : '+'"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </section>

            {{-- ── Links ── --}}
            <section class="rounded-xl border border-slate-200 dark:border-slate-800">
                <header class="flex items-center justify-between px-4 py-3">
                    <button type="button" @click="toggleSec('links')" class="flex items-center gap-2">
                        <span class="text-slate-400" x-text="s.open.links ? '▾' : '▸'"></span>
                        <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Internal &amp; external links</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800" x-text="(selectedLinks().internal.length + selectedLinks().external.length) + ' selected'"></span>
                    </button>
                    <button type="button" @click="runStrategy(['link_suggestions'])" :disabled="s.busyCard.link_suggestions" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800" title="Regenerate links">↻</button>
                </header>
                <div x-show="s.open.links" class="space-y-4 border-t border-slate-100 p-4 dark:border-slate-800">
                    <template x-for="kind in ['internal','external']" :key="kind">
                        <div>
                            <p class="mb-1.5 text-xs font-semibold capitalize text-slate-700 dark:text-slate-300" x-text="kind"></p>
                            <ul class="space-y-1.5">
                                <template x-for="(l, i) in linkSuggestions()[kind]" :key="'s'+i">
                                    <li class="rounded-lg border p-2 transition" :class="isLinkSelected(kind, l.url) ? 'border-indigo-300 bg-indigo-50/50 dark:border-indigo-500/40 dark:bg-indigo-500/5' : 'border-slate-100 dark:border-slate-800'">
                                        <label class="flex items-start gap-2">
                                            <input type="checkbox" :checked="isLinkSelected(kind, l.url)" @change="toggleLink(kind, l)" class="mt-1">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium text-slate-800 dark:text-slate-100" x-text="l.anchor"></span>
                                                <a :href="l.url" target="_blank" rel="noopener" class="block truncate text-[11px] text-indigo-600 hover:underline dark:text-indigo-400" x-text="l.url"></a>
                                                <span x-show="l.rationale" class="mt-0.5 block text-[11px] text-slate-500 dark:text-slate-400" x-text="l.rationale"></span>
                                            </span>
                                        </label>
                                    </li>
                                </template>
                                <template x-for="l in manualOnly(kind)" :key="'m'+l.url">
                                    <li class="flex items-center justify-between gap-2 rounded-lg border border-indigo-300 bg-indigo-50/50 p-2 dark:border-indigo-500/40 dark:bg-indigo-500/5">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-slate-800 dark:text-slate-100" x-text="l.anchor"></span>
                                            <span class="block truncate text-[11px] text-slate-500 dark:text-slate-400" x-text="l.url"></span>
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <span class="rounded-full bg-slate-200 px-1.5 py-0.5 text-[9px] font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">Custom</span>
                                            <button type="button" @click="removeManualLink(kind, l.url)" class="rounded p-1 text-slate-400 hover:text-red-500" title="Remove">✕</button>
                                        </span>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="linkSuggestions()[kind].length === 0 && manualOnly(kind).length === 0" class="text-[11px] text-slate-400">No suggestions yet. Add your own below or regenerate.</p>
                        </div>
                    </template>

                    {{-- Manual add --}}
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">Add a link manually</span>
                            <span class="text-[11px] text-slate-400" x-text="workspaceDomain ? ('URLs on ' + workspaceDomain + ' = internal, anywhere else = external.') : 'URL hostname picks internal vs external.'"></span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text" x-model="s.manualAnchor" placeholder="Anchor text" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <input type="url" x-model="s.manualUrl" placeholder="https://example.com/page" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <span x-show="detectedManual()" class="rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize" :class="detectedManual() === 'internal' ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'" x-text="detectedManual()"></span>
                            <button type="button" @click="addManualLink()" :disabled="s.manualAnchor.trim() === '' || s.manualUrl.trim() === ''" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-white disabled:opacity-50 dark:border-slate-700 dark:text-slate-300">Add</button>
                        </div>
                        <p x-show="s.manualError" class="mt-1 text-[11px] text-red-600 dark:text-red-400" x-text="s.manualError"></p>
                    </div>
                </div>
            </section>

            <template x-if="s.error"><div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="s.error"></div></template>

            <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                <button type="button" @click="goToStep('brief')" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400">← Back</button>
                <button type="button" @click="goToStep('images')" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Next: choose images →</button>
            </div>
        </div>
    </template>
</div>
