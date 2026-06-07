{{--
    EBQ AI Studio — dashboard surface. Single-page Alpine app driving
    the launcher (categories + tool grid), the tool runner (dynamic
    form + per-type result renderer), and brand-voice settings off the
    same payload `AiStudioController::index` ships.

    The plugin's HQ React bundle is the reference UX. This Blade view
    re-implements the same flow in Tailwind + Alpine so the dashboard
    isn't dependent on the WP build pipeline.
--}}
<x-layouts.app>
    @php
        $catalog = $catalog ?? ['categories' => [], 'tools' => []];
        $brandVoice = $brandVoice ?? ['configured' => false, 'samples_count' => 0];
        $tierGate = $tierGate ?? null;
        $featureLocked = ! ($aiWriterEnabled ?? false);
        $runUrlTemplate = route('ai-studio.run', ['toolId' => '__TOOL_ID__']);
        $brandVoiceUpdateUrl = route('ai-studio.brand-voice.update');
        $brandVoiceDestroyUrl = route('ai-studio.brand-voice.destroy');
    @endphp

    {{-- Alpine component registry — declared before the `x-data` div so it
         is in scope by the time Alpine evaluates the binding. --}}
    <script>
    function aiStudio(opts) {
        const CAT_CHIPS = {
            research:    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
            writing:     'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
            improvement: 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
            marketing:   'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
            ecommerce:   'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
            media:       'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
            misc:        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        };

        return {
            catalog: opts.catalog,
            featureLocked: opts.featureLocked,
            tierGate: opts.tierGate,
            brandVoice: opts.brandVoice,
            csrf: opts.csrf,
            runUrlTemplate: opts.runUrlTemplate,
            brandVoiceUpdateUrl: opts.brandVoiceUpdateUrl,
            brandVoiceDestroyUrl: opts.brandVoiceDestroyUrl,
            wizardUrl: opts.wizardUrl,

            view: 'launcher',
            activeCat: 'all',
            search: '',

            activeTool: null,
            formValues: {},
            formValuesRaw: {},
            loading: false,
            result: null,
            copied: false,

            bvSamples: [''],
            bvSaving: false,
            bvError: '',

            headerTitle() {
                if (this.view === 'tool' && this.activeTool) return this.activeTool.name;
                if (this.view === 'brand-voice') return 'Brand voice';
                return 'AI Studio';
            },
            headerSub() {
                if (this.view === 'tool' && this.activeTool) return this.activeTool.description;
                if (this.view === 'brand-voice') return 'Train every tool on your house voice.';
                return `${this.catalog.tools.length} AI tools, grounded in your GSC data, rank tracking, and the EBQ network.`;
            },

            categoryLabel(id) {
                const c = (this.catalog.categories || []).find(c => c.id === id);
                return c ? c.label : id;
            },
            categoryChipClass(id) {
                return CAT_CHIPS[id] || CAT_CHIPS.misc;
            },

            filteredTools() {
                const q = this.search.trim().toLowerCase();
                return (this.catalog.tools || []).filter(t => {
                    if (this.activeCat !== 'all' && t.category !== this.activeCat) return false;
                    if (q === '') return true;
                    return (t.name + ' ' + (t.description || '')).toLowerCase().includes(q);
                });
            },

            openTool(toolId) {
                const tool = (this.catalog.tools || []).find(t => t.id === toolId);
                if (!tool) return;
                // The Blog Post Wizard is a marker tool: running it just
                // returns a redirect directive. Its real surface is the
                // multi-step wizard page — open that instead of the
                // generic tool form (which would dump the directive JSON).
                if (toolId === 'blog-post-wizard' && this.wizardUrl) {
                    window.location.href = this.wizardUrl;
                    return;
                }
                this.activeTool = tool;
                this.formValues = {};
                this.formValuesRaw = {};
                (tool.inputs || []).forEach(f => {
                    if (f.type === 'tags') {
                        this.formValues[f.key] = Array.isArray(f.default) ? f.default : [];
                        this.formValuesRaw[f.key] = (this.formValues[f.key] || []).join(', ');
                    } else if (f.default !== undefined && f.default !== null) {
                        this.formValues[f.key] = f.default;
                    } else {
                        this.formValues[f.key] = f.type === 'number' ? null : '';
                    }
                });
                this.result = null;
                this.copied = false;
                this.view = 'tool';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },

            resetForm() {
                if (this.activeTool) this.openTool(this.activeTool.id);
            },

            exitToLauncher() {
                this.view = 'launcher';
                this.activeTool = null;
                this.result = null;
            },

            openBrandVoice() {
                this.view = 'brand-voice';
                this.bvSamples = [''];
                this.bvError = '';
            },

            formValid() {
                if (!this.activeTool) return false;
                for (const f of (this.activeTool.inputs || [])) {
                    if (!f.required) continue;
                    const v = this.formValues[f.key];
                    if (v === undefined || v === null) return false;
                    if (typeof v === 'string' && v.trim() === '') return false;
                    if (Array.isArray(v) && v.length === 0) return false;
                }
                return true;
            },

            async runActive() {
                if (!this.activeTool || this.loading) return;
                if (this.featureLocked) return;
                this.loading = true;
                this.result = null;
                this.copied = false;
                try {
                    const res = await fetch(this.runUrlTemplate.replace('__TOOL_ID__', this.activeTool.id), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(this.formValues),
                    });
                    let payload = null;
                    try { payload = await res.json(); } catch (e) { /* empty */ }
                    if (!payload) {
                        this.result = { ok: false, error: 'network', message: `Server returned ${res.status}.` };
                    } else {
                        this.result = payload;
                    }
                } catch (err) {
                    this.result = { ok: false, error: 'network', message: err.message || 'Request failed.' };
                } finally {
                    this.loading = false;
                }
            },

            errorTitle(r) {
                switch (r.error) {
                    case 'tier_required':    return `Available on ${(r.required_tier || 'Pro').toString()}`;
                    case 'feature_disabled': return 'AI Studio is turned off for this site';
                    case 'validation':       return 'Check your inputs';
                    case 'rate_limited':     return 'Slow down a moment';
                    case 'no_website':       return 'Pick a website first';
                    default:                 return 'Something went wrong';
                }
            },

            async copyResult() {
                if (!this.result || !this.result.ok) return;
                let text = '';
                const v = this.result.value;
                switch (this.result.output_type) {
                    case 'text':
                    case 'html':
                        text = String(v || '');
                        break;
                    case 'titles':
                    case 'list':
                        text = (v || []).join('\n');
                        break;
                    case 'table':
                        text = [(v?.headers || []).join('\t'), ...((v?.rows || []).map(r => r.join('\t')))].join('\n');
                        break;
                    case 'links':
                        text = (v || []).map(l => `${l.anchor || ''} — ${l.url}`).join('\n');
                        break;
                    case 'faq':
                        text = (v || []).map(qa => `Q: ${qa.question}\nA: ${qa.answer}`).join('\n\n');
                        break;
                    default:
                        text = JSON.stringify(v, null, 2);
                }
                try {
                    await navigator.clipboard.writeText(text);
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 1500);
                } catch (e) { /* ignore */ }
            },

            formatDate(iso) {
                if (!iso) return '';
                try {
                    const d = new Date(iso);
                    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                } catch (e) { return iso; }
            },

            addBvSample() { if (this.bvSamples.length < 5) this.bvSamples.push(''); },
            removeBvSample(i) { this.bvSamples.splice(i, 1); if (this.bvSamples.length === 0) this.bvSamples.push(''); },

            bvHasValidSamples() {
                return this.bvSamples.some(s => (s || '').trim().length >= 200);
            },

            async saveBrandVoice() {
                if (this.bvSaving || this.featureLocked) return;
                this.bvError = '';
                const samples = this.bvSamples.map(s => (s || '').trim()).filter(s => s.length >= 200);
                if (samples.length === 0) {
                    this.bvError = 'Each sample needs at least 200 characters.';
                    return;
                }
                this.bvSaving = true;
                try {
                    const res = await fetch(this.brandVoiceUpdateUrl, {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ samples }),
                    });
                    const payload = await res.json();
                    if (!res.ok || payload.configured === undefined) {
                        this.bvError = payload.message || payload.error || 'Could not save voice.';
                    } else {
                        this.brandVoice = payload;
                        this.bvSamples = [''];
                    }
                } catch (e) {
                    this.bvError = e.message || 'Network error.';
                } finally {
                    this.bvSaving = false;
                }
            },

            async clearBrandVoice() {
                if (this.bvSaving) return;
                if (!confirm('Clear the brand voice fingerprint? Tools will revert to a generic style.')) return;
                this.bvSaving = true;
                try {
                    const res = await fetch(this.brandVoiceDestroyUrl, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (res.ok) {
                        this.brandVoice = { configured: false, samples_count: 0 };
                    }
                } finally {
                    this.bvSaving = false;
                }
            },
        };
    }
    </script>

    <div
        class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8"
        x-data="aiStudio({
            catalog: @js($catalog),
            brandVoice: @js($brandVoice),
            featureLocked: @js($featureLocked),
            tierGate: @js($tierGate),
            runUrlTemplate: @js($runUrlTemplate),
            brandVoiceUpdateUrl: @js($brandVoiceUpdateUrl),
            brandVoiceDestroyUrl: @js($brandVoiceDestroyUrl),
            wizardUrl: @js(route('ai-studio.wizard')),
            csrf: @js(csrf_token()),
        })"
        x-cloak
    >
        {{-- Header --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-sm">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"/>
                        </svg>
                    </span>
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100" x-text="headerTitle()"></h1>
                </div>
                <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400" x-text="headerSub()"></p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="openBrandVoice()"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    :class="brandVoice.configured
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900/40 dark:bg-emerald-500/10 dark:text-emerald-300'
                        : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
                    :title="brandVoice.configured ? 'Brand voice is active' : 'Brand voice not set'"
                >
                    <span
                        class="inline-block h-2 w-2 rounded-full"
                        :class="brandVoice.configured ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                    ></span>
                    <span x-text="brandVoice.configured ? 'Brand voice on' : 'Set brand voice'"></span>
                </button>

                <template x-if="view !== 'launcher'">
                    <button
                        type="button"
                        @click="exitToLauncher()"
                        class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M15 18l-6-6 6-6"/>
                        </svg>
                        Back to tools
                    </button>
                </template>
            </div>
        </div>

        {{-- Tier-required banner --}}
        <template x-if="featureLocked && tierGate">
            <div class="mb-5 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 9v3.75m0 3.75h.008v.008H12v-.008zM21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <p class="font-semibold">
                            AI Studio is a <span x-text="(tierGate.required_tier || 'Pro')" class="capitalize"></span> feature.
                        </p>
                        <p class="mt-0.5 text-amber-800/90 dark:text-amber-200/80">
                            You can browse the catalog. Upgrade to run any tool.
                        </p>
                    </div>
                </div>
                <a
                    href="{{ route('billing.show') }}"
                    class="inline-flex items-center justify-center rounded-md bg-amber-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500"
                >
                    See plans
                </a>
            </div>
        </template>

        {{-- Launcher view --}}
        <div x-show="view === 'launcher'" class="grid grid-cols-1 gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
            <aside class="space-y-3">
                <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <label class="sr-only" for="ai-studio-search">Search tools</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
                        </svg>
                        <input
                            id="ai-studio-search"
                            type="search"
                            x-model.debounce.150ms="search"
                            placeholder="Search 47 tools…"
                            class="w-full rounded-lg border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm text-slate-700 placeholder-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
                        >
                    </div>
                </div>

                <nav class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li>
                            <button
                                type="button"
                                @click="activeCat = 'all'"
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition"
                                :class="activeCat === 'all'
                                    ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                    : 'text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                            >
                                <span>All tools</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="catalog.tools.length"></span>
                            </button>
                        </li>
                        <template x-for="cat in catalog.categories" :key="cat.id">
                            <li>
                                <button
                                    type="button"
                                    @click="activeCat = cat.id"
                                    :title="cat.description"
                                    class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition"
                                    :class="activeCat === cat.id
                                        ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                        : 'text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                                >
                                    <span class="truncate" x-text="cat.label"></span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold tabular-nums text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="cat.tool_count"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </nav>
            </aside>

            <section>
                <template x-if="filteredTools().length === 0">
                    <div class="rounded-xl border border-dashed border-slate-200 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                        <p>No tools match your search.</p>
                        <button type="button" @click="search = ''; activeCat = 'all'" class="mt-2 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">Reset</button>
                    </div>
                </template>

                <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <template x-for="tool in filteredTools()" :key="tool.id">
                        <li>
                            <button
                                type="button"
                                @click="openTool(tool.id)"
                                class="group flex h-full w-full flex-col rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-500/50"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider"
                                        :class="categoryChipClass(tool.category)"
                                        x-text="categoryLabel(tool.category)"
                                    ></span>
                                    <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-slate-500 dark:text-slate-400" :title="`~${tool.est_credits} credits`">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/>
                                        </svg>
                                        <span>~<span x-text="tool.est_credits"></span></span>
                                    </span>
                                </div>
                                <h3 class="mt-3 text-sm font-semibold text-slate-900 group-hover:text-indigo-700 dark:text-slate-100 dark:group-hover:text-indigo-300" x-text="tool.name"></h3>
                                <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400" x-text="tool.description"></p>
                            </button>
                        </li>
                    </template>
                </ul>
            </section>
        </div>

        {{-- Tool runner view --}}
        <div x-show="view === 'tool'" class="space-y-5">
            <template x-if="activeTool">
                <div>
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider"
                                        :class="categoryChipClass(activeTool.category)"
                                        x-text="categoryLabel(activeTool.category)"
                                    ></span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        ~<span x-text="activeTool.est_credits"></span> credits
                                    </span>
                                </div>
                                <h2 class="mt-2 text-base font-semibold text-slate-900 dark:text-slate-100" x-text="activeTool.name"></h2>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" x-text="activeTool.description"></p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
                        {{-- Input form --}}
                        <form @submit.prevent="runActive()" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Inputs</h3>

                            <template x-for="field in (activeTool.inputs || [])" :key="field.key">
                                <div>
                                    <label :for="`field-${field.key}`" class="flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        <span>
                                            <span x-text="field.label"></span>
                                            <span x-show="field.required" class="ml-0.5 text-red-500">*</span>
                                        </span>
                                        <span x-show="field.max_length" class="text-[10px] font-normal text-slate-400" x-text="`${(formValues[field.key] || '').length}/${field.max_length}`"></span>
                                    </label>

                                    {{-- text / url --}}
                                    <template x-if="field.type === 'text' || field.type === 'url' || field.type === 'post_picker'">
                                        <input
                                            :id="`field-${field.key}`"
                                            :type="field.type === 'url' || field.type === 'post_picker' ? 'url' : 'text'"
                                            :maxlength="field.max_length || null"
                                            :placeholder="field.placeholder || ''"
                                            x-model="formValues[field.key]"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
                                        >
                                    </template>

                                    {{-- textarea / rich_text --}}
                                    <template x-if="field.type === 'textarea' || field.type === 'rich_text'">
                                        <textarea
                                            :id="`field-${field.key}`"
                                            :maxlength="field.max_length || null"
                                            :placeholder="field.placeholder || ''"
                                            :rows="field.type === 'rich_text' ? 10 : 5"
                                            x-model="formValues[field.key]"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
                                        ></textarea>
                                    </template>

                                    {{-- number --}}
                                    <template x-if="field.type === 'number'">
                                        <input
                                            :id="`field-${field.key}`"
                                            type="number"
                                            :placeholder="field.placeholder || ''"
                                            x-model.number="formValues[field.key]"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
                                        >
                                    </template>

                                    {{-- select --}}
                                    <template x-if="field.type === 'select'">
                                        <select
                                            :id="`field-${field.key}`"
                                            x-model="formValues[field.key]"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                                        >
                                            <template x-if="!field.required">
                                                <option value="">— Select —</option>
                                            </template>
                                            <template x-for="opt in (field.options || [])" :key="opt.value">
                                                <option :value="opt.value" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                    </template>

                                    {{-- tags --}}
                                    <template x-if="field.type === 'tags'">
                                        <div>
                                            <input
                                                :id="`field-${field.key}`"
                                                type="text"
                                                :placeholder="field.placeholder || 'Comma-separated'"
                                                x-model="formValuesRaw[field.key]"
                                                @input="formValues[field.key] = ($event.target.value || '').split(',').map(s => s.trim()).filter(Boolean)"
                                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
                                            >
                                            <div class="mt-1.5 flex flex-wrap gap-1" x-show="(formValues[field.key] || []).length > 0">
                                                <template x-for="(tag, i) in (formValues[field.key] || [])" :key="i + '-' + tag">
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300" x-text="tag"></span>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <p x-show="field.help" class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="field.help"></p>
                                </div>
                            </template>

                            <div class="flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                                <button
                                    type="button"
                                    @click="resetForm()"
                                    class="text-xs font-semibold text-slate-500 transition hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                                >
                                    Reset
                                </button>
                                <button
                                    type="submit"
                                    :disabled="loading || featureLocked || !formValid()"
                                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 dark:disabled:bg-slate-700 dark:disabled:text-slate-400"
                                >
                                    <template x-if="loading">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M21 12a9 9 0 11-6.219-8.56"/>
                                        </svg>
                                    </template>
                                    <template x-if="!loading">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>
                                        </svg>
                                    </template>
                                    <span x-text="loading ? 'Generating…' : (featureLocked ? 'Upgrade to run' : 'Generate')"></span>
                                </button>
                            </div>
                        </form>

                        {{-- Result --}}
                        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Result</h3>
                                <div class="flex items-center gap-2" x-show="result && result.ok">
                                    <span x-show="result?.cached" class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Cached</span>
                                    <span x-show="result?.model" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="result?.model"></span>
                                    <span x-show="result?.usage?.total" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="`${result.usage.total} tok`"></span>
                                    <button type="button" @click="copyResult()" class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200" :title="copied ? 'Copied' : 'Copy'">
                                        <svg x-show="!copied" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/>
                                        </svg>
                                        <svg x-show="copied" class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            {{-- Loading skeleton --}}
                            <template x-if="loading">
                                <div class="space-y-2">
                                    <div class="h-3 w-3/4 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                                    <div class="h-3 w-full animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                                    <div class="h-3 w-5/6 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                                    <div class="h-3 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                                    <p class="pt-2 text-xs text-slate-400">This can take up to a minute on cold cache.</p>
                                </div>
                            </template>

                            {{-- Empty --}}
                            <template x-if="!loading && !result">
                                <div class="flex h-40 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-200 text-center text-xs text-slate-400 dark:border-slate-700">
                                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                                    </svg>
                                    <p>Fill in the inputs, hit Generate.</p>
                                </div>
                            </template>

                            {{-- Error --}}
                            <template x-if="!loading && result && !result.ok">
                                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                                    <p class="font-semibold" x-text="errorTitle(result)"></p>
                                    <p class="mt-1 text-xs" x-text="result.message || result.error"></p>
                                    <template x-if="result.required_tier">
                                        <a href="{{ route('billing.show') }}" class="mt-2 inline-flex rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">See plans</a>
                                    </template>
                                </div>
                            </template>

                            {{-- Success — by output_type --}}
                            <template x-if="!loading && result && result.ok">
                                <div class="text-sm text-slate-800 dark:text-slate-100">
                                    {{-- text --}}
                                    <template x-if="result.output_type === 'text'">
                                        <div class="whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-sm leading-relaxed dark:bg-slate-800/60" x-text="String(result.value || '')"></div>
                                    </template>

                                    {{-- html --}}
                                    <template x-if="result.output_type === 'html'">
                                        <div class="prose prose-sm max-w-none rounded-lg bg-slate-50 p-3 dark:prose-invert dark:bg-slate-800/60" x-html="result.value || ''"></div>
                                    </template>

                                    {{-- titles --}}
                                    <template x-if="result.output_type === 'titles'">
                                        <ul class="space-y-2">
                                            <template x-for="(t, i) in (result.value || [])" :key="i">
                                                <li class="flex items-start gap-2 rounded-lg border border-slate-100 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-800/60">
                                                    <span class="inline-flex h-5 w-5 flex-none items-center justify-center rounded-full bg-indigo-100 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300" x-text="i + 1"></span>
                                                    <span class="text-sm" x-text="t"></span>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>

                                    {{-- list --}}
                                    <template x-if="result.output_type === 'list'">
                                        <ul class="list-disc space-y-1 pl-5">
                                            <template x-for="(t, i) in (result.value || [])" :key="i">
                                                <li class="text-sm" x-text="t"></li>
                                            </template>
                                        </ul>
                                    </template>

                                    {{-- table --}}
                                    <template x-if="result.output_type === 'table'">
                                        <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-800">
                                            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                                                <thead class="bg-slate-50 dark:bg-slate-800/60">
                                                    <tr>
                                                        <template x-for="(h, i) in (result.value?.headers || [])" :key="i">
                                                            <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400" x-text="h"></th>
                                                        </template>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
                                                    <template x-for="(row, ri) in (result.value?.rows || [])" :key="ri">
                                                        <tr>
                                                            <template x-for="(cell, ci) in row" :key="ci">
                                                                <td class="whitespace-pre-wrap px-3 py-2 text-xs text-slate-700 dark:text-slate-200" x-text="cell"></td>
                                                            </template>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </template>

                                    {{-- links --}}
                                    <template x-if="result.output_type === 'links'">
                                        <ul class="space-y-2">
                                            <template x-for="(lnk, i) in (result.value || [])" :key="i">
                                                <li class="rounded-lg border border-slate-100 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-800/60">
                                                    <a :href="lnk.url" target="_blank" rel="noopener" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400" x-text="lnk.anchor || lnk.url"></a>
                                                    <p class="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400" x-text="lnk.url"></p>
                                                    <p x-show="lnk.rationale" class="mt-1 text-xs text-slate-600 dark:text-slate-300" x-text="lnk.rationale"></p>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>

                                    {{-- faq --}}
                                    <template x-if="result.output_type === 'faq'">
                                        <ul class="space-y-2">
                                            <template x-for="(qa, i) in (result.value || [])" :key="i">
                                                <li class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                                                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100" x-text="qa.question"></p>
                                                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300" x-text="qa.answer"></p>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>

                                    {{-- schema / json — code block --}}
                                    <template x-if="result.output_type === 'schema' || result.output_type === 'json'">
                                        <pre class="max-h-[480px] overflow-auto rounded-lg bg-slate-900 p-3 text-xs leading-relaxed text-slate-100 dark:bg-slate-950"><code x-text="JSON.stringify(result.value, null, 2)"></code></pre>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Brand voice view --}}
        <div x-show="view === 'brand-voice'" class="mx-auto max-w-3xl">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Brand voice</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Paste 1–5 of your best posts (200+ characters each). We extract a private voice fingerprint that every tool uses so the output sounds like you, not generic AI.
                </p>

                <template x-if="brandVoice.configured">
                    <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 text-sm text-emerald-900 dark:text-emerald-200">
                                <p class="font-semibold">Voice active</p>
                                <p class="mt-0.5 text-xs">
                                    <span x-text="brandVoice.samples_count"></span> sample<span x-show="brandVoice.samples_count !== 1">s</span>
                                    <span x-show="brandVoice.last_extracted_at">, last extracted <span x-text="formatDate(brandVoice.last_extracted_at)"></span></span>
                                </p>
                                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                                    <template x-if="brandVoice.tone">
                                        <div>
                                            <dt class="text-emerald-700/70 dark:text-emerald-300/70">Tone</dt>
                                            <dd class="font-semibold" x-text="brandVoice.tone"></dd>
                                        </div>
                                    </template>
                                    <template x-if="brandVoice.person">
                                        <div>
                                            <dt class="text-emerald-700/70 dark:text-emerald-300/70">Person</dt>
                                            <dd class="font-semibold" x-text="brandVoice.person"></dd>
                                        </div>
                                    </template>
                                    <template x-if="brandVoice.avg_sentence_words">
                                        <div>
                                            <dt class="text-emerald-700/70 dark:text-emerald-300/70">Avg sentence</dt>
                                            <dd class="font-semibold"><span x-text="brandVoice.avg_sentence_words"></span> words</dd>
                                        </div>
                                    </template>
                                    <template x-if="brandVoice.vocabulary_band">
                                        <div>
                                            <dt class="text-emerald-700/70 dark:text-emerald-300/70">Vocabulary</dt>
                                            <dd class="font-semibold" x-text="brandVoice.vocabulary_band"></dd>
                                        </div>
                                    </template>
                                </dl>
                            </div>
                            <button
                                type="button"
                                @click="clearBrandVoice()"
                                :disabled="bvSaving"
                                class="rounded-md border border-emerald-300 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50 disabled:opacity-50 dark:border-emerald-400/30 dark:bg-slate-900 dark:text-emerald-300 dark:hover:bg-slate-800"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </template>

                <div class="mt-5 space-y-3">
                    <template x-for="(_, i) in bvSamples" :key="i">
                        <div>
                            <label :for="`bv-sample-${i}`" class="flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-300">
                                <span>Sample <span x-text="i + 1"></span></span>
                                <button type="button" @click="removeBvSample(i)" x-show="bvSamples.length > 1" class="text-[11px] font-normal text-slate-400 hover:text-red-500">Remove</button>
                            </label>
                            <textarea
                                :id="`bv-sample-${i}`"
                                x-model="bvSamples[i]"
                                rows="6"
                                placeholder="Paste a full post (200+ characters). Plain text or HTML both work — we strip tags."
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
                            ></textarea>
                            <p class="mt-1 text-[11px] text-slate-400"><span x-text="(bvSamples[i] || '').length"></span> characters</p>
                        </div>
                    </template>

                    <div class="flex items-center justify-between">
                        <button
                            type="button"
                            @click="addBvSample()"
                            x-show="bvSamples.length < 5"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            Add another sample
                        </button>
                        <button
                            type="button"
                            @click="saveBrandVoice()"
                            :disabled="bvSaving || featureLocked || !bvHasValidSamples()"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 dark:disabled:bg-slate-700"
                        >
                            <template x-if="bvSaving">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                            </template>
                            <span x-text="bvSaving ? 'Extracting…' : 'Save voice'"></span>
                        </button>
                    </div>
                </div>

                <template x-if="bvError">
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" x-text="bvError"></div>
                </template>
            </div>
        </div>
    </div>

</x-layouts.app>
