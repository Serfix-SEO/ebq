<div class="space-y-6">
    <x-content.connect-wordpress />

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
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
            x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="relative flex items-start gap-3 overflow-hidden rounded-2xl border border-success/25 bg-white p-4 ps-5 shadow-sm ring-1 ring-success/5 dark:border-success/25 dark:bg-slate-900">
            <span class="absolute inset-y-0 start-0 w-1 bg-gradient-to-b from-success to-emerald-600"></span>
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-success/10 text-success">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            </span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ session('review-status') }}</p>
            </div>
            <button type="button" @click="show = false" class="shrink-0 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="{{ __('Dismiss') }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    @endif

    @if ($generating)
        {{-- ── Generating: the real draft (or a skeleton) blurred behind a live
             progress overlay. Stays up through research → write → polish →
             REVISIONS → images until the article is fully finalized. ── --}}
        {{-- Cap the whole block to roughly one viewport (inline style — Tailwind
             arbitrary max-h-[…] isn't in the prebuilt bundle) so the centered
             progress card always lands on the first fold, no matter how long the
             draft teaser behind it grows. --}}
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-800"
             style="max-height: calc(100vh - 11rem)"
             @if (! ($progress['failed'] ?? false)) wire:poll.3s @endif>
            {{-- Teaser behind: the draft-in-progress if we have one, else a skeleton --}}
            <div class="pointer-events-none select-none overflow-hidden p-6 blur sm:p-10" aria-hidden="true">
                @if ($article)
                    <div class="prose prose-slate mx-auto max-w-3xl opacity-60 dark:prose-invert">
                        <h1>{{ $article->h1 ?: $topic?->title }}</h1>
                        {!! $previewHtml !!}
                    </div>
                @else
                    <div class="mx-auto max-w-3xl space-y-5 opacity-60">
                        <h1 class="text-3xl font-extrabold tracking-tight text-slate-800 dark:text-slate-200">{{ $topic?->title }}</h1>
                        <div class="h-40 w-full animate-pulse rounded-xl bg-slate-200 dark:bg-slate-800"></div>
                        @foreach ([5,4,5] as $bi => $blk)
                            <div class="space-y-2.5">
                                <div class="h-5 w-1/3 animate-pulse rounded bg-slate-300 dark:bg-slate-700"></div>
                                @for ($i = 0; $i < $blk; $i++)
                                    <div class="h-3.5 animate-pulse rounded bg-slate-200 dark:bg-slate-800" style="width: {{ [100,96,92,88,98][$i % 5] }}%"></div>
                                @endfor
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Overlay: the live progress card — scrolls within the capped box
                 if the card is taller than the viewport (small screens). --}}
            <div class="absolute inset-0 flex items-center justify-center overflow-y-auto bg-white/60 p-4 backdrop-blur-sm dark:bg-slate-900/60">
                <div class="my-auto w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">
                        {{ ($progress['failed'] ?? false) ? __('Needs attention') : __('Creating your article') }}
                    </p>
                    <h2 class="mt-0.5 text-lg font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ $topic?->title }}</h2>

                    <div class="mt-4 space-y-1">
                        @foreach (($progress['steps'] ?? []) as $step)
                            <div class="flex items-center gap-3 rounded-xl px-3 py-2 {{ $step['state'] === 'active' ? 'bg-orange-50 dark:bg-orange-950' : '' }}">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full
                                    {{ $step['state'] === 'done' ? 'bg-success text-white'
                                       : ($step['state'] === 'active' ? 'bg-orange-600 text-white'
                                       : ($step['state'] === 'failed' ? 'bg-error text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-800')) }}">
                                    @if ($step['state'] === 'done')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    @elseif ($step['state'] === 'active')
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    @elseif ($step['state'] === 'failed')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    @else
                                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                    @endif
                                </span>
                                <span class="flex-1 text-sm font-medium {{ $step['state'] === 'pending' ? 'text-slate-400 dark:text-slate-500' : 'text-slate-800 dark:text-slate-100' }}">{{ $step['label'] }}</span>
                                @if ($step['state'] === 'active')<span class="text-xs font-semibold text-orange-600">{{ __('in progress') }}</span>@endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 flex items-center gap-2 border-t border-slate-100 pt-3 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        @if ($progress['failed'] ?? false)
                            <span class="flex-1">{{ __('Generation stopped. You can try again.') }}</span>
                            <button wire:click="retryGeneration" class="rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-orange-700">{{ __('Try again') }}</button>
                        @else
                            <svg class="h-4 w-4 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <span>{{ $progress['etaText'] ?? __('working…') }}</span>
                            <span class="ml-auto text-xs text-slate-400">{{ __('This page updates itself.') }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @elseif ($article === null)
        <div class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('This article has not been written yet.') }}
        </div>
    @else
        {{-- Poll while publishing so the status flips to Published without a reload. --}}
        <div class="grid gap-6 lg:grid-cols-3" @if($topic?->status === \App\Models\ContentTopic::STATUS_PUBLISHING) wire:poll.5s @endif>
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
                        @endif
                    </div>

                    @if ($traffic)
                        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div class="flex items-center gap-2">
                                <svg class="h-4 w-4 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25"/></svg>
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('What this article is worth') }}</div>
                            </div>
                            <div class="mt-3 flex items-baseline gap-1.5">
                                <span class="text-2xl font-extrabold text-success">+{{ number_format($traffic['low']) }}</span>
                                <span class="text-sm text-slate-500 dark:text-slate-400">{{ __('extra visitors / month') }}</span>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">
                                @if ($traffic['volume'] > 0)
                                    {{ __('":kw" gets about :v searches a month. This is a fair, conservative estimate for a new article that settles onto page one over time — realistically :low–:high visits/mo, not the best case.', [
                                        'kw' => $topic->target_keyword,
                                        'v' => number_format($traffic['volume']),
                                        'low' => number_format($traffic['low']),
                                        'high' => number_format($traffic['high']),
                                    ]) }}
                                @else
                                    {{ __('A fair, conservative estimate once this ranks — not the best case.') }}
                                @endif
                            </p>
                        </div>
                    @endif

                    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Search preview') }}</div>
                        <div class="mt-3 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                            <div class="truncate text-sm font-medium text-blue-700 dark:text-blue-400">{{ $article->meta_title }}</div>
                            <div class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-500">{{ $topic->website?->domain }}/{{ $article->slug }}</div>
                            <div class="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{{ $article->meta_description }}</div>
                        </div>
                    </div>

                    {{-- Item 5: articles publish as classic HTML; the client converts to blocks in WP if wanted. --}}
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs leading-5 text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
                        <div class="flex items-start gap-2">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                            <span>{{ __('This publishes to WordPress as clean HTML in the classic editor. If you prefer Gutenberg blocks, open the post in WordPress and use “Convert to blocks” — your SEO fields and images carry over unchanged.') }}</span>
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
                        @if (\App\Livewire\Content\ContentCalendar::publishableNow($topic))
                            @if ($publishConnected)
                                <button wire:click="publishNow" wire:confirm="{{ __('Publish this article to your site now?') }}"
                                        class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-success px-4 py-2.5 text-sm font-bold text-white hover:brightness-110">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                    {{ __('Publish now') }}
                                </button>
                            @else
                                <a href="{{ route('content.integrations') }}" wire:navigate class="block w-full rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-medium text-orange-600 hover:bg-orange-50 dark:border-slate-700 dark:hover:bg-slate-800">{{ __('Connect a site to publish →') }}</a>
                            @endif
                        @endif
                    @endif
                </div>
            </div>

            {{-- ── Article preview / editor ─────────────────────────── --}}
            <div class="lg:col-span-2">
                {{-- Featured image kept out of the body (per settings) — show it here
                     so the reviewer still sees the post's thumbnail. --}}
                @if (! empty($featuredImage))
                    <div class="mb-4 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <img src="{{ $featuredImage->url() }}" alt="{{ $featuredImage->alt_text }}" class="h-48 w-full object-cover" />
                        <div class="flex items-start gap-2 px-4 py-3">
                            <svg class="mt-0.5 h-4 w-4 flex-none text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M18 9h.008v.008H18V9zm.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/><rect x="2.25" y="4.5" width="19.5" height="15" rx="2.25"/></svg>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ __('Featured image (thumbnail)') }}</p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Used as your post\'s featured image in WordPress. It is not shown at the top of the article body because you turned that off in settings.') }}</p>
                            </div>
                        </div>
                    </div>
                @endif
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
