<div class="space-y-6">
    {{-- Load the TipTap editor bundle on the INITIAL page render (not inside the
         $editing branch): a @vite <script type=module> morphed in later on the Edit
         click does NOT execute, so tiptapEditor would never register. @assets loads
         it once and persists across wire:navigate. --}}
    @assets
        @vite('resources/js/editor.js')
    @endassets

    <x-content.connect-integration />
    <x-content.connect-gsc :website="$topic?->website" />

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
        {{-- ── Client feedback: "Do you like this article?" ─────────────── --}}
        @unless ($editing)
            @php
                $fbOpts = [
                    ['r' => \App\Models\ContentArticleFeedback::RATING_LOVE, 'label' => __('I love it'), 'on' => 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
                    ['r' => \App\Models\ContentArticleFeedback::RATING_REWRITES, 'label' => __('Needs small rewrites'), 'on' => 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-700 dark:bg-orange-500/10 dark:text-orange-300'],
                    ['r' => \App\Models\ContentArticleFeedback::RATING_WRONG, 'label' => __('It\'s fundamentally wrong'), 'on' => 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
                ];
            @endphp
            <div class="space-y-3">
                <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900">
                    <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Do you like this article?') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($fbOpts as $o)
                            <button type="button" wire:click="rateArticle('{{ $o['r'] }}')"
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-semibold transition',
                                    $o['on'] => $feedbackRating === $o['r'],
                                    'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $feedbackRating !== $o['r'],
                                ])>
                                {{-- Beautiful branded reaction icons (self-coloured gradients;
                                     unique gradient ids so they never collide on the page). --}}
                                @switch($o['r'])
                                    @case(\App\Models\ContentArticleFeedback::RATING_LOVE)
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <defs><linearGradient id="fbLove" x1="12" y1="4" x2="12" y2="21" gradientUnits="userSpaceOnUse"><stop stop-color="#FB7185"/><stop offset="1" stop-color="#E11D48"/></linearGradient></defs>
                                            <path d="M12 20.7S4.3 16.2 2.6 11.2C1.5 8 3.2 5 6.2 5c1.9 0 3.2 1.1 3.9 2.2.2.3.6.5.9.5s.7-.2.9-.5C12.6 6.1 13.9 5 15.8 5c3 0 4.7 3 3.6 6.2-1.7 5-7.4 9.5-7.4 9.5z" fill="url(#fbLove)"/>
                                            <path d="M6.6 8.2c-.8.3-1.3 1-1.4 1.9" stroke="#fff" stroke-opacity=".6" stroke-width="1.3" stroke-linecap="round"/>
                                        </svg>
                                        @break
                                    @case(\App\Models\ContentArticleFeedback::RATING_REWRITES)
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <defs><linearGradient id="fbRw" x1="5" y1="19" x2="19" y2="5" gradientUnits="userSpaceOnUse"><stop stop-color="#FDBA74"/><stop offset="1" stop-color="#EA580C"/></linearGradient></defs>
                                            <path d="M14.6 5.1l4.3 4.3L9.3 19l-4.6 1 1-4.6L14.6 5.1z" fill="url(#fbRw)"/>
                                            <path d="M13.4 6.3l4.3 4.3" stroke="#fff" stroke-opacity=".55" stroke-width="1.2" stroke-linecap="round"/>
                                            <path d="M18.7 2.6l.66 1.64 1.64.66-1.64.66-.66 1.64-.66-1.64-1.64-.66 1.64-.66.66-1.64z" fill="#FBBF24"/>
                                        </svg>
                                        @break
                                    @case(\App\Models\ContentArticleFeedback::RATING_WRONG)
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <defs><linearGradient id="fbWrong" x1="12" y1="3" x2="12" y2="21" gradientUnits="userSpaceOnUse"><stop stop-color="#FB7185"/><stop offset="1" stop-color="#E11D48"/></linearGradient></defs>
                                            <circle cx="12" cy="12" r="9" fill="url(#fbWrong)"/>
                                            <circle cx="9" cy="10.3" r="1.15" fill="#fff"/>
                                            <circle cx="15" cy="10.3" r="1.15" fill="#fff"/>
                                            <path d="M8.4 15.8c1-1.35 2.2-2 3.6-2s2.6.65 3.6 2" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
                                        </svg>
                                        @break
                                @endswitch
                                {{ $o['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if (in_array($feedbackRating, [\App\Models\ContentArticleFeedback::RATING_REWRITES, \App\Models\ContentArticleFeedback::RATING_WRONG], true))
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <label class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Tell us what needs to change (optional)') }}</label>
                        <textarea wire:model="feedbackComment" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200" placeholder="{{ __('e.g. tone too formal, wrong facts in section 2, missing our USP…') }}"></textarea>
                        <div class="mt-2 flex items-center justify-end gap-3">
                            @if ($feedbackSaved)<span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Sent — thank you') }}</span>@endif
                            <button type="button" wire:click="saveFeedbackComment" class="rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-slate-800 dark:bg-slate-700">{{ __('Send feedback') }}</button>
                        </div>
                    </div>
                @elseif ($feedbackRating === \App\Models\ContentArticleFeedback::RATING_LOVE)
                    <p class="px-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Thanks for the love! Our team sees your feedback.') }}</p>
                @endif
            </div>
        @endunless

        {{-- Poll while publishing so the status flips to Published without a reload. --}}
        <div class="grid gap-6 lg:grid-cols-3" @if($topic?->status === \App\Models\ContentTopic::STATUS_PUBLISHING) wire:poll.5s @endif>
            {{-- ── Quality panel ────────────────────────────────────── --}}
            {{-- ca-sticky-col: stays in view while the long article scrolls
                 (grid items stretch by default, so align-self:start is required
                 for sticky to have room to move). --}}
            <div class="space-y-4 ca-sticky-col">
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
                                    <span class="min-w-0">
                                        {{ $check['label'] }}
                                        @if (! $check['passed'] && ! empty($check['hint']))
                                            <span class="mt-0.5 block text-xs font-normal text-slate-500 dark:text-slate-400">{{ $check['hint'] }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-center gap-4">
                            {{-- Prefer the live-recomputed score (same scorer + view-time
                                 context the edit ring uses) so non-edit and edit rings
                                 agree. Fall back to the stored generation-time score when
                                 the live score isn't computed yet — e.g. the very first
                                 render right after generation, before currentArticle has
                                 loaded, where scoreCurrent() returns 0 (prod 2026-07-28).
                                 The stored score can drift ~1 pt as crawl/context changes. --}}
                            @php
                                $nonEditScore = $liveScore > 0 ? $liveScore : (int) ($article->seo_score ?? 0);
                            @endphp
                            @include('reports.charts.ring', [
                                'value' => (float) $nonEditScore,
                                'display' => (int) $nonEditScore,
                                'label' => __('Content quality'),
                                'color' => $nonEditScore >= 85 ? '#059669' : ($nonEditScore >= 60 ? '#F26419' : '#e11d48'),
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

                    {{-- Targeted SEO: the keyphrases this article is optimized for
                         (article focus override falls back to the topic target). --}}
                    @php
                        $primaryKw = trim((string) ($article->focus_keyword ?: $topic->target_keyword));
                        $secondaryKws = array_values(array_filter(array_map('trim', (array) ($topic->secondary_keywords ?? []))));
                    @endphp
                    @if ($primaryKw !== '' || $secondaryKws !== [])
                        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div class="flex items-center gap-2">
                                <svg class="h-4 w-4 text-orange-600 dark:text-orange-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('SEO targets') }}</div>
                            </div>
                            @if ($primaryKw !== '')
                                <div class="mt-3">
                                    <div class="text-xs font-medium text-slate-400">{{ __('Focus keyphrase') }}</div>
                                    <div class="mt-1 inline-flex items-center rounded-lg bg-orange-100 px-2.5 py-1 text-sm font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">{{ $primaryKw }}</div>
                                </div>
                            @endif
                            @if ($secondaryKws !== [])
                                <div class="mt-3">
                                    <div class="text-xs font-medium text-slate-400">{{ __('Secondary keyphrases') }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        @foreach ($secondaryKws as $kw)
                                            <span class="inline-flex items-center rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $kw }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Keyword Tracker CTA — track this article's keywords for
                                 GSC/GA performance, or jump to the Tracker if already added. --}}
                            @if ($trackerLimit > 0 || $isTracked)
                                <div class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                                    @if ($isTracked)
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                                {{ __('Tracking performance') }}
                                            </span>
                                            <a href="{{ route('content.tracker') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">{{ __('View in Tracker') }}</a>
                                        </div>
                                        <button type="button" wire:click="untrackKeywords" class="mt-2 text-xs text-slate-400 hover:text-rose-600 dark:hover:text-rose-400">{{ __('Stop tracking') }}</button>
                                    @elseif ($trackerLimit > 0 && $trackerUsed >= $trackerLimit)
                                        <p class="text-sm font-medium text-amber-600 dark:text-amber-400">{{ __('Your tracker is full') }} ({{ number_format($trackerUsed) }}/{{ number_format($trackerLimit) }}).</p>
                                        <a href="{{ route('content.tracker') }}" wire:navigate class="mt-1 inline-block text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">{{ __('Remove a keyword to track these') }} &rarr;</a>
                                    @else
                                        <button type="button" wire:click="trackKeywords"
                                            class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                            {{ __('Track these keywords') }}
                                        </button>
                                        <p class="mt-2 text-xs text-slate-400">{{ number_format($trackerUsed) }}/{{ number_format($trackerLimit) }} {{ __('tracked') }}</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif

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
                        {{-- startEditing loads the whole editor bundle — without
                             feedback the click reads as dead (same complaint as
                             the calendar cards, 2026-08-08). --}}
                        <button wire:click="startEditing" wire:loading.attr="disabled" wire:target="startEditing"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-orange-300 bg-orange-50 px-4 py-2.5 text-sm font-semibold text-orange-700 hover:bg-orange-100 disabled:opacity-70 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-300">
                            <svg wire:loading wire:target="startEditing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <span wire:loading.remove wire:target="startEditing">{{ __('Edit article') }}</span>
                            <span wire:loading wire:target="startEditing">{{ __('Opening editor…') }}</span>
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
                    @php
                        $fieldClass = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100';
                        $labelClass = 'block text-xs font-semibold text-slate-600 dark:text-slate-400';
                        // Preview fall-throughs mirror the plugin: SEO title → H1;
                        // social title/desc → SEO title/meta description.
                        $gTitle = trim($editMetaTitle) !== '' ? $editMetaTitle : $editH1;
                        $gDesc = $editMetaDescription;
                        $gUrl = trim($siteHost) !== '' ? $siteHost : __('your-site.com');
                        $socialTitle = trim($editOgTitle) !== '' ? $editOgTitle : $gTitle;
                        $socialDesc = trim($editOgDescription) !== '' ? $editOgDescription : $gDesc;
                        // Social image: explicit OG → Twitter → the article's
                        // generated featured image (so the card is never blank).
                        $socialImg = trim($editOgImage) !== '' ? trim($editOgImage)
                            : (trim($editTwitterImage) !== '' ? trim($editTwitterImage) : trim($socialImageFallback ?? ''));
                        $len = fn ($s, $max) => mb_strlen(trim((string) $s)).'/'.$max;
                        $countClass = fn ($s, $lo, $hi) => (mb_strlen(trim((string) $s)) >= $lo && mb_strlen(trim((string) $s)) <= $hi) ? 'text-emerald-600' : 'text-slate-400';
                    @endphp
                    {{-- ── Plugin-style SEO panel: live audit + per-article SEO fields.
                         Collapsible + default COLLAPSED. Body uses x-show (not
                         x-if) so the inputs stay in the DOM while collapsed and
                         still submit with "Save changes". ── --}}
                    <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
                         x-data="{ open: false, social: false, advanced: false }">
                        <button type="button" @click="open = !open" class="flex w-full items-center gap-2 text-left">
                            <x-nodus :size="22" class="shrink-0" />
                            <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ __('SEO settings for this article') }}</h3>
                            <span class="ms-auto rounded-full px-2 py-0.5 text-[11px] font-bold text-white" style="background: {{ $liveScore >= 85 ? '#059669' : ($liveScore >= 60 ? '#F26419' : '#e11d48') }};">{{ (int) $liveScore }}/100</span>
                            <svg class="h-4 w-4 shrink-0 text-slate-400 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </button>

                        <div x-show="open" x-cloak class="mt-4 space-y-4">
                        {{-- Google snippet preview --}}
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                            <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Google preview') }}</p>
                            <div class="truncate text-xs" style="color:#4d5156;">{{ $gUrl }} <span style="color:#5f6368;">&rsaquo; {{ Str::limit(trim($editSlug), 40) }}</span></div>
                            <div class="truncate text-lg leading-snug" style="color:#1a0dab;">{{ $gTitle !== '' ? $gTitle : __('Your SEO title appears here') }}</div>
                            <div class="text-xs leading-snug" style="color:#4d5156;">{{ Str::limit($gDesc !== '' ? $gDesc : __('Your meta description preview appears here — write 130–158 characters that earn the click.'), 160) }}</div>
                        </div>

                        {{-- General fields --}}
                        <div class="grid gap-3">
                            <div>
                                <label class="{{ $labelClass }}">{{ __('Focus keyphrase') }}</label>
                                <input type="text" wire:model.live.debounce.600ms="editFocusKeyword" placeholder="{{ __('The main keyword this article targets') }}" class="{{ $fieldClass }}" />
                                <p class="mt-1 text-[11px] text-slate-400">{{ __('The live audit scores against this. Defaults to the topic keyword.') }}</p>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">{{ __('Headline (H1)') }}</label>
                                <input type="text" wire:model.live.debounce.600ms="editH1" class="{{ $fieldClass }} font-semibold" />
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="{{ $labelClass }}">{{ __('SEO title') }} <span class="font-normal {{ $countClass($editMetaTitle, 40, 60) }}">({{ $len($editMetaTitle, 60) }})</span></label>
                                    <input type="text" wire:model.live.debounce.600ms="editMetaTitle" class="{{ $fieldClass }}" />
                                </div>
                                <div>
                                    <label class="{{ $labelClass }}">{{ __('URL slug') }}</label>
                                    <input type="text" wire:model.live.debounce.600ms="editSlug" class="{{ $fieldClass }}" />
                                </div>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">{{ __('Meta description') }} <span class="font-normal {{ $countClass($editMetaDescription, 130, 158) }}">({{ $len($editMetaDescription, 158) }})</span></label>
                                <textarea wire:model.live.debounce.600ms="editMetaDescription" rows="2" class="{{ $fieldClass }}"></textarea>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">{{ __('Canonical URL') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                                <input type="url" wire:model.live.debounce.600ms="editCanonical" placeholder="https://" class="{{ $fieldClass }}" />
                            </div>
                        </div>

                        {{-- Social (OpenGraph + Twitter) — collapsible --}}
                        <div class="rounded-lg border border-slate-200 dark:border-slate-700">
                            <button type="button" @click="social = !social" class="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-bold text-slate-700 dark:text-slate-200">
                                <span>{{ __('Social preview (Facebook / X)') }}</span>
                                <svg class="h-4 w-4 transition" :class="social && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                            </button>
                            <div x-show="social" class="space-y-3 border-t border-slate-200 p-3 dark:border-slate-700">
                                {{-- Social card preview --}}
                                <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                                    @if ($socialImg !== '')
                                        <img src="{{ $socialImg }}" alt="" class="h-32 w-full object-cover" onerror="this.style.display='none'" />
                                    @else
                                        <div class="flex h-24 w-full items-center justify-center bg-slate-100 text-[11px] text-slate-400 dark:bg-slate-800">{{ __('No social image set') }}</div>
                                    @endif
                                    <div class="bg-slate-50 p-2 dark:bg-slate-800/50">
                                        <div class="text-[10px] uppercase text-slate-400">{{ $gUrl }}</div>
                                        <div class="truncate text-xs font-semibold text-slate-700 dark:text-slate-200">{{ $socialTitle !== '' ? $socialTitle : __('Social title') }}</div>
                                        <div class="truncate text-[11px] text-slate-500 dark:text-slate-400">{{ $socialDesc }}</div>
                                    </div>
                                </div>
                                <div>
                                    <label class="{{ $labelClass }}">{{ __('Social image URL') }}</label>
                                    <input type="url" wire:model.live.debounce.600ms="editOgImage" placeholder="https://" class="{{ $fieldClass }}" />
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="{{ $labelClass }}">{{ __('OpenGraph title') }}</label>
                                        <input type="text" wire:model.live.debounce.600ms="editOgTitle" placeholder="{{ __('Falls back to SEO title') }}" class="{{ $fieldClass }}" />
                                    </div>
                                    <div>
                                        <label class="{{ $labelClass }}">{{ __('X (Twitter) title') }}</label>
                                        <input type="text" wire:model.live.debounce.600ms="editTwitterTitle" placeholder="{{ __('Falls back to SEO title') }}" class="{{ $fieldClass }}" />
                                    </div>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="{{ $labelClass }}">{{ __('OpenGraph description') }}</label>
                                        <textarea wire:model.live.debounce.600ms="editOgDescription" rows="2" placeholder="{{ __('Falls back to meta description') }}" class="{{ $fieldClass }}"></textarea>
                                    </div>
                                    <div>
                                        <label class="{{ $labelClass }}">{{ __('X (Twitter) description') }}</label>
                                        <textarea wire:model.live.debounce.600ms="editTwitterDescription" rows="2" placeholder="{{ __('Falls back to meta description') }}" class="{{ $fieldClass }}"></textarea>
                                    </div>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="{{ $labelClass }}">{{ __('X (Twitter) image URL') }}</label>
                                        <input type="url" wire:model.live.debounce.600ms="editTwitterImage" placeholder="{{ __('Falls back to social image') }}" class="{{ $fieldClass }}" />
                                    </div>
                                    <div>
                                        <label class="{{ $labelClass }}">{{ __('X (Twitter) card') }}</label>
                                        <select wire:model.live="editTwitterCard" class="{{ $fieldClass }}">
                                            <option value="summary_large_image">{{ __('Large image') }}</option>
                                            <option value="summary">{{ __('Summary') }}</option>
                                            <option value="app">{{ __('App') }}</option>
                                            <option value="player">{{ __('Player') }}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Advanced (robots) — collapsible --}}
                        <div class="rounded-lg border border-slate-200 dark:border-slate-700">
                            <button type="button" @click="advanced = !advanced" class="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-bold text-slate-700 dark:text-slate-200">
                                <span>{{ __('Advanced (search engine directives)') }}</span>
                                <svg class="h-4 w-4 transition" :class="advanced && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                            </button>
                            <div x-show="advanced" class="space-y-2 border-t border-slate-200 p-3 dark:border-slate-700">
                                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" wire:model.live="editNoindex" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500/30" />
                                    {{ __('Noindex — ask search engines not to list this page') }}
                                </label>
                                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" wire:model.live="editNofollow" class="rounded border-slate-300 text-orange-600 focus:ring-orange-500/30" />
                                    {{ __('Nofollow — ask search engines not to follow links on this page') }}
                                </label>
                            </div>
                        </div>
                        </div>{{-- /x-show open --}}
                    </div>

                    {{-- Full WYSIWYG editor (TipTap). Alpine-owned inside wire:ignore
                         so Livewire re-renders never clobber the ProseMirror DOM.
                         editor.js is loaded at the TOP of this component (NOT here) —
                         a @vite tag morphed in on the Edit click never executes. --}}
                    @php($editorI18n = [
                        'linkUrl' => __('Link URL'),
                        'imageUrl' => __('Image URL'),
                        'altPrompt' => __('Describe this image (alt text) — helps SEO'),
                        'genPrompt' => __('Describe the image you want to generate'),
                        'genTimeout' => __('The image is taking longer than expected — check back shortly.'),
                        'genFailed' => __('Image generation did not complete. Try again.'),
                        'aiFailed' => __('The AI edit did not complete. Try again.'),
                        'placeholder' => __('Start writing…'),
                    ])
                    <div wire:ignore x-data="tiptapEditor(@js($previewHtml), @js($editorI18n))" x-init="mountEditor($refs.mount)" class="relative">
                        {{-- Format toolbar --}}
                        <div class="sticky top-2 z-20 mb-2 flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            {{-- text style --}}
                            <button type="button" @click="cmd('toggleBold')" :class="activeCls('bold')" class="rounded-lg px-2.5 py-1.5 text-sm font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Bold') }}">B</button>
                            <button type="button" @click="cmd('toggleItalic')" :class="activeCls('italic')" class="rounded-lg px-2.5 py-1.5 text-sm italic text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Italic') }}">I</button>
                            <button type="button" @click="cmd('toggleUnderline')" :class="activeCls('underline')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 underline hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Underline') }}">U</button>
                            <button type="button" @click="cmd('toggleStrike')" :class="activeCls('strike')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 line-through hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Strikethrough') }}">S</button>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            {{-- blocks --}}
                            <button type="button" @click="cmd('toggleHeading', { level: 2 })" :class="activeCls('heading', { level: 2 })" class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">H2</button>
                            <button type="button" @click="cmd('toggleHeading', { level: 3 })" :class="activeCls('heading', { level: 3 })" class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">H3</button>
                            <button type="button" @click="cmd('toggleHeading', { level: 4 })" :class="activeCls('heading', { level: 4 })" class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">H4</button>
                            <button type="button" @click="cmd('setParagraph')" :class="activeCls('paragraph')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Text') }}</button>
                            <button type="button" @click="cmd('toggleBlockquote')" :class="activeCls('blockquote')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Quote') }}">&rdquo;</button>
                            <button type="button" @click="cmd('toggleCodeBlock')" :class="activeCls('codeBlock')" class="rounded-lg px-2.5 py-1.5 text-xs font-mono text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Code block') }}">&lt;/&gt;</button>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            {{-- lists --}}
                            <button type="button" @click="cmd('toggleBulletList')" :class="activeCls('bulletList')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Bullet list') }}">&bull;</button>
                            <button type="button" @click="cmd('toggleOrderedList')" :class="activeCls('orderedList')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Numbered list') }}">1.</button>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            {{-- align --}}
                            <button type="button" @click="cmd('setTextAlign', 'left')" :class="activeCls('paragraph', { textAlign: 'left' })" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Align left') }}">&#8676;</button>
                            <button type="button" @click="cmd('setTextAlign', 'center')" :class="activeCls('paragraph', { textAlign: 'center' })" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Align center') }}">&#8596;</button>
                            <button type="button" @click="cmd('setTextAlign', 'right')" :class="activeCls('paragraph', { textAlign: 'right' })" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Align right') }}">&#8677;</button>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            {{-- link, hr, table --}}
                            <button type="button" @click="toggleLink()" :class="activeCls('link')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Link') }}">🔗</button>
                            <button type="button" @click="cmd('setHorizontalRule')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Divider') }}">―</button>
                            <div class="relative" @click.outside="tableOpen = false" x-data="{ tableOpen: false }">
                                <button type="button" @click="tableOpen = !tableOpen" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Table') }}">{{ __('Table') }} ▾</button>
                                <div x-show="tableOpen" x-cloak class="absolute left-0 top-full z-40 mt-1 w-48 rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                    <button type="button" @click="insertTable(); tableOpen=false" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Insert table') }}</button>
                                    <button type="button" @click="cmd('addRowAfter')" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Add row') }}</button>
                                    <button type="button" @click="cmd('deleteRow')" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Delete row') }}</button>
                                    <button type="button" @click="cmd('addColumnAfter')" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Add column') }}</button>
                                    <button type="button" @click="cmd('deleteColumn')" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Delete column') }}</button>
                                    <button type="button" @click="cmd('deleteTable'); tableOpen=false" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Delete table') }}</button>
                                </div>
                            </div>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            {{-- images --}}
                            <input type="file" x-ref="file" accept="image/*" class="hidden" @change="uploadImage($event.target.files[0])">
                            <div class="relative" @click.outside="imgOpen = false" x-data="{ imgOpen: false }">
                                <button type="button" @click="imgOpen = !imgOpen" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Image') }}">🖼️</button>
                                <div x-show="imgOpen" x-cloak class="absolute left-0 top-full z-40 mt-1 w-44 rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                    <button type="button" @click="pickImage(); imgOpen=false" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Upload image') }}</button>
                                    <button type="button" @click="insertImageUrl(); imgOpen=false" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Insert by URL') }}</button>
                                    <button type="button" @click="generateImage(); imgOpen=false" class="block w-full rounded-lg px-2 py-1 text-left text-xs font-medium text-slate-700 hover:bg-orange-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('AI generate') }}</button>
                                </div>
                            </div>
                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"></span>
                            {{-- history + clear --}}
                            <button type="button" @click="cmd('undo')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Undo') }}">↺</button>
                            <button type="button" @click="cmd('redo')" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Redo') }}">↻</button>
                            <button type="button" @click="clearFormat()" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" title="{{ __('Clear formatting') }}">✕</button>
                            <span class="ml-auto flex items-center gap-2">
                                <span x-show="notice" x-cloak x-text="notice" class="text-xs font-medium text-red-600 dark:text-red-400"></span>
                                <span x-show="busy || genBusy" class="flex items-center gap-1.5 text-xs font-medium text-orange-600">
                                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    <span x-text="genBusy ? '{{ __('Generating image…') }}' : '{{ __('Working…') }}'"></span>
                                </span>
                                <button type="button" @click="$wire.cancelEditing()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('Cancel') }}</button>
                                <button type="button" @click="save()" class="rounded-lg bg-orange-600 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-orange-700">{{ __('Save changes') }}</button>
                            </span>
                        </div>

                        {{-- Floating selection AI menu --}}
                        <div x-show="menuOpen" x-cloak :style="`position:absolute; left:${menuX}px; top:${menuY}px; z-index:30;`"
                             class="flex items-center gap-0.5 rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                             @mousedown.prevent>
                            {{-- In-place loading state (shown while the AI request runs) --}}
                            <span x-show="busy" class="flex items-center gap-1.5 px-2 py-1 text-xs font-semibold text-orange-600">
                                <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                {{ __('AI is editing…') }}
                            </span>
                            {{-- Action buttons (hidden while busy) --}}
                            <span x-show="!busy" class="flex items-center gap-0.5">
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
                            </span>
                        </div>

                        {{-- ProseMirror mount (TipTap builds its editable node inside) --}}
                        <div x-ref="mount"
                            class="rounded-xl border-2 border-orange-200 bg-white p-6 focus-within:border-orange-400 sm:p-8 dark:border-orange-900 dark:bg-slate-900"></div>

                        <p class="mt-2 text-center text-xs text-slate-400">{{ __('Format with the toolbar, add images and tables, or select text to edit it with AI. Changes save as a new draft version — nothing is lost.') }}</p>
                    </div>
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
                /* TipTap / ProseMirror chrome — NONE of this is in the prebuilt
                   Tailwind bundle, so it must live here as raw CSS. */
                /* Sticky quality/checks column on wide screens — keeps Live SEO
                   checks in view while the long article body scrolls. Own scroll
                   when the check list is taller than the viewport. */
                @media (min-width: 1024px) {
                    .ca-sticky-col { position: sticky; top: 1.5rem; align-self: start; max-height: calc(100vh - 2rem); overflow-y: auto; }
                }
                .ca-preview:focus, .ca-preview:focus-visible { outline: none; }
                .ca-preview table { border-collapse: collapse; width: 100%; margin: 1.25rem 0; overflow: hidden; table-layout: fixed; }
                .ca-preview td, .ca-preview th { border: 1px solid #cbd5e1; padding: .5rem .75rem; vertical-align: top; position: relative; }
                .ca-preview th { background: #f1f5f9; font-weight: 700; text-align: start; }
                .dark .ca-preview td, .dark .ca-preview th { border-color: #334155; }
                .dark .ca-preview th { background: #1e293b; }
                .ca-preview .selectedCell:after { content: ''; position: absolute; inset: 0; background: rgba(194,65,12,.12); pointer-events: none; }
                .ca-preview .column-resize-handle { position: absolute; right: -2px; top: 0; bottom: 0; width: 4px; background: #f97316; pointer-events: none; }
                .ca-preview .ProseMirror-selectednode { outline: 2px solid #f97316; border-radius: .5rem; }
                .ca-preview p.is-editor-empty:first-child:before { content: attr(data-placeholder); float: left; color: #94a3b8; pointer-events: none; height: 0; }
                [x-cloak] { display: none !important; }
            </style>
        </div>
    @endif
</div>
