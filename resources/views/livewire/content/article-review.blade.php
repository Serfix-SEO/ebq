<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('content.index') }}" class="text-sm text-slate-500 hover:text-orange-600 dark:text-slate-400">&larr; {{ __('Back to calendar') }}</a>
            <h1 class="mt-1 truncate text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ $topic?->title }}</h1>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                {{ $topic?->target_keyword }}
                @if ($topic?->scheduled_for) · {{ __('planned for :date', ['date' => $topic->scheduled_for->translatedFormat('M j, Y')]) }} @endif
            </p>
        </div>
        @if ($presentation)
            <span class="rounded-full bg-{{ $presentation['color'] }}-100 px-2.5 py-1 text-xs font-semibold text-{{ $presentation['color'] }}-700">{{ $presentation['label'] }}</span>
        @endif
    </div>

    @if (session('review-status'))
        <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm font-medium text-success">
            {{ session('review-status') }}
        </div>
    @endif

    @if ($article === null)
        <div class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('This article has not been written yet.') }}
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            {{-- ── Quality panel ────────────────────────────────────── --}}
            <div class="space-y-4">
                @if ($editing)
                    {{-- Live on-page checks (re-score as you type — same rules as the site plugin) --}}
                    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-center gap-4">
                            @include('reports.charts.ring', [
                                'value' => (float) $liveScore,
                                'display' => (int) $liveScore,
                                'label' => __('Live score'),
                                'color' => $liveScore >= 85 ? '#059669' : ($liveScore >= 60 ? '#F26419' : '#e11d48'),
                                'size' => 84,
                            ])
                            <div>
                                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Live SEO checks') }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Updates as you edit') }}</div>
                            </div>
                        </div>
                        <ul class="mt-4 max-h-[26rem] space-y-1 overflow-y-auto pr-1">
                            @foreach (collect($liveChecks)->sortBy('passed') as $check)
                                <li class="flex items-start gap-2 py-0.5 text-sm {{ $check['passed'] ? 'text-slate-500 dark:text-slate-400' : 'text-slate-800 dark:text-slate-200 font-medium' }}" wire:key="chk-{{ $check['code'] }}">
                                    @if ($check['passed'])
                                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    @else
                                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                                    @endif
                                    {{ $check['label'] }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-center gap-4">
                            @include('reports.charts.ring', [
                                'value' => (float) ($article->seo_score ?? 0),
                                'display' => (int) ($article->seo_score ?? 0),
                                'label' => __('Content quality'),
                                'color' => ($article->seo_score ?? 0) >= 85 ? '#059669' : (($article->seo_score ?? 0) >= 60 ? '#F26419' : '#e11d48'),
                                'size' => 84,
                            ])
                            <div>
                                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Content quality') }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ __(':words words', ['words' => number_format($article->word_count)]) }} · {{ __('draft :v', ['v' => $article->version]) }}</div>
                            </div>
                        </div>

                        @if ($issueLabels->isEmpty())
                            <p class="mt-4 rounded-lg bg-success/10 px-3 py-2 text-sm text-success">
                                {{ __('All quality checks passed.') }}
                            </p>
                        @else
                            <div class="mt-4">
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Could be even better') }}</div>
                                <ul class="mt-2 space-y-1.5">
                                    @foreach ($issueLabels as $label)
                                        <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                                            <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400"></span>{{ $label }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Search preview') }}</div>
                        <div class="mt-3 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                            <div class="truncate text-sm font-medium text-blue-700 dark:text-blue-400">{{ $article->meta_title }}</div>
                            <div class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-500">{{ $topic->website?->domain }}/{{ $article->slug }}</div>
                            <div class="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{{ $article->meta_description }}</div>
                        </div>
                    </div>
                @endif

                <div class="space-y-2">
                    @if (! $editing)
                        <button wire:click="startEditing" class="w-full rounded-lg border border-orange-300 bg-orange-50 px-4 py-2.5 text-sm font-semibold text-orange-700 hover:bg-orange-100 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-300">
                            {{ __('Edit article') }}
                        </button>
                        @if ($topic->status === \App\Models\ContentTopic::STATUS_READY)
                            <button wire:click="approve" class="w-full rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-orange-700">
                                {{ __('Approve this article') }}
                            </button>
                        @elseif ($topic->status === \App\Models\ContentTopic::STATUS_SCHEDULED)
                            <p class="rounded-lg bg-success/10 px-3 py-2 text-center text-sm text-success">{{ __('Approved and ready to go.') }}</p>
                        @endif
                        <button wire:click="requestNewDraft" wire:confirm="{{ __('Write a completely new draft for this topic?') }}"
                            class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            {{ __('Request a new draft') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- ── Article preview / editor ─────────────────────────── --}}
            <div class="lg:col-span-2">
                @if (! $editing)
                    <article class="ca-preview prose prose-slate max-w-none rounded-xl border border-slate-200 bg-white p-6 sm:p-8 dark:border-slate-800 dark:bg-slate-900 dark:prose-invert">
                        <h1>{{ $article->h1 }}</h1>
                        {!! $previewHtml !!}
                    </article>
                @else
                    {{-- Editable meta fields --}}
                    <div class="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Headline (H1)') }}</label>
                            <input type="text" wire:model.live.debounce.600ms="editH1"
                                class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('SEO title') }} <span class="font-normal text-slate-400">({{ mb_strlen($editMetaTitle) }}/60)</span></label>
                                <input type="text" wire:model.live.debounce.600ms="editMetaTitle"
                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Meta description') }} <span class="font-normal text-slate-400">({{ mb_strlen($editMetaDescription) }}/158)</span></label>
                                <input type="text" wire:model.live.debounce.600ms="editMetaDescription"
                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            </div>
                        </div>
                    </div>

                    {{-- Editor (Alpine-owned; wire:ignore so Livewire never clobbers the caret) --}}
                    <div wire:ignore x-data="caArticleEditor()" x-init="init($refs.editor)" class="relative">
                        {{-- Format toolbar --}}
                        <div class="sticky top-2 z-20 mb-2 flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <button type="button" @click="cmd('bold')" class="rounded-lg px-2.5 py-1.5 text-sm font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Bold') }}">B</button>
                            <button type="button" @click="cmd('italic')" class="rounded-lg px-2.5 py-1.5 text-sm italic text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Italic') }}">I</button>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            <button type="button" @click="block('h2')" class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">H2</button>
                            <button type="button" @click="block('h3')" class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">H3</button>
                            <button type="button" @click="block('p')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Text') }}</button>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            <button type="button" @click="cmd('insertUnorderedList')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Bullet list') }}">• —</button>
                            <button type="button" @click="link()" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Link') }}">🔗</button>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            <button type="button" @click="cmd('undo')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Undo') }}">↺</button>
                            <span class="ml-auto flex items-center gap-2">
                                <span x-show="busy" class="flex items-center gap-1.5 text-xs font-medium text-orange-600">
                                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    {{ __('AI is editing…') }}
                                </span>
                                <button type="button" @click="$wire.cancelEditing()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('Cancel') }}</button>
                                <button type="button" @click="$wire.saveEdits($refs.editor.innerHTML)" class="rounded-lg bg-orange-600 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-orange-700">{{ __('Save changes') }}</button>
                            </span>
                        </div>

                        {{-- Floating selection AI menu --}}
                        <div x-show="menuOpen" x-cloak :style="`position:absolute; left:${menuX}px; top:${menuY}px; z-index:30;`"
                             class="flex items-center gap-0.5 rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                             @mousedown.prevent>
                            <span class="px-1.5 text-xs font-bold text-orange-600">AI</span>
                            <button type="button" @click="ai('rewrite-content')" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Rewrite') }}</button>
                            <button type="button" @click="ai('simplify-content')" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Simplify') }}</button>
                            <button type="button" @click="ai('shorten-content')" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Shorten') }}</button>
                            <button type="button" @click="ai('expand-content')" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Expand') }}</button>
                            <button type="button" @click="ai('fix-grammar')" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Fix grammar') }}</button>
                            <div class="relative" @click.outside="toneOpen = false">
                                <button type="button" @click="toneOpen = !toneOpen" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Tone') }} ▾</button>
                                <div x-show="toneOpen" x-cloak class="absolute left-0 top-full z-40 mt-1 w-36 rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                    <template x-for="t in ['formal','casual','empathetic','authoritative','playful','concise']" :key="t">
                                        <button type="button" @click="ai('change-tone', t)" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium capitalize text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800" x-text="t"></button>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- The editable article body --}}
                        <article x-ref="editor" contenteditable="true" spellcheck="true"
                            @input.debounce.800ms="$wire.rescore($refs.editor.innerHTML)"
                            @mouseup="onSelect()" @keyup.debounce.300ms="onSelect()"
                            class="ca-preview prose prose-slate max-w-none rounded-xl border-2 border-orange-200 bg-white p-6 outline-none focus:border-orange-400 sm:p-8 dark:border-orange-900 dark:bg-slate-900 dark:prose-invert">
                            {!! $previewHtml !!}
                        </article>

                        <p class="mt-2 text-center text-xs text-slate-400">{{ __('Select any text to edit it with AI. Changes save as a new draft version — nothing is lost.') }}</p>
                    </div>

                    <script>
                        function caArticleEditor() {
                            return {
                                busy: false, menuOpen: false, toneOpen: false, menuX: 0, menuY: 0,
                                savedRange: null, root: null,
                                init(el) { this.root = el; },
                                cmd(c) { this.root.focus(); document.execCommand(c, false, null); },
                                block(tag) { this.root.focus(); document.execCommand('formatBlock', false, tag.toUpperCase()); },
                                link() {
                                    const url = prompt('{{ __('Link URL') }}');
                                    if (url) { this.root.focus(); document.execCommand('createLink', false, url); }
                                },
                                onSelect() {
                                    const sel = window.getSelection();
                                    if (!sel || sel.isCollapsed || !this.root.contains(sel.anchorNode)) {
                                        if (!this.toneOpen) this.menuOpen = false;
                                        return;
                                    }
                                    const range = sel.getRangeAt(0);
                                    if (range.toString().trim().length < 3) { this.menuOpen = false; return; }
                                    this.savedRange = range.cloneRange();
                                    const rect = range.getBoundingClientRect();
                                    const host = this.root.parentElement.getBoundingClientRect();
                                    this.menuX = Math.max(0, rect.left - host.left);
                                    this.menuY = Math.max(0, rect.top - host.top - 44);
                                    this.menuOpen = true;
                                },
                                async ai(tool, tone = null) {
                                    if (this.busy || !this.savedRange) return;
                                    const text = this.savedRange.toString();
                                    this.busy = true; this.menuOpen = false; this.toneOpen = false;
                                    try {
                                        const out = await this.$wire.aiEdit(tool, text, tone);
                                        if (out) {
                                            const sel = window.getSelection();
                                            sel.removeAllRanges(); sel.addRange(this.savedRange);
                                            this.savedRange.deleteContents();
                                            this.savedRange.insertNode(document.createTextNode(out));
                                            this.$wire.rescore(this.root.innerHTML);
                                        }
                                    } finally {
                                        this.busy = false; this.savedRange = null;
                                    }
                                },
                            };
                        }
                    </script>
                @endif
            </div>

            {{-- Table-of-contents styling (the TOC ships inside the article
                 HTML as <nav class="content-toc">; scoped so it never bleeds). --}}
            <style>
                .ca-preview { scroll-behavior: smooth; }
                .ca-preview .content-toc { margin: 0 0 1.75rem; padding: 1rem 1.25rem; border: 1px solid #e2e8f0; border-radius: 0.75rem; background: #f8fafc; }
                .ca-preview .content-toc__title { margin: 0 0 .5rem; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
                .ca-preview .content-toc ul { margin: 0; padding: 0; list-style: none; }
                .ca-preview .content-toc__item { margin: .25rem 0; }
                .ca-preview .content-toc__item--sub { margin-inline-start: 1rem; font-size: .9em; }
                .ca-preview .content-toc a { color: #c2410c; text-decoration: none; }
                .ca-preview .content-toc a:hover { text-decoration: underline; }
                .dark .ca-preview .content-toc { border-color: #334155; background: #0f172a; }
                .dark .ca-preview .content-toc__title { color: #94a3b8; }
                .dark .ca-preview .content-toc a { color: #fb923c; }
                .ca-preview figure.content-image { margin: 1.25rem 0; }
                .ca-preview figure.content-image img { width: 100%; height: auto; border-radius: .75rem; display: block; }
                .ca-preview figure.content-image figcaption { margin-top: .4rem; font-size: .8rem; color: #64748b; text-align: center; }
                [x-cloak] { display: none !important; }
            </style>
        </div>
    @endif
</div>
