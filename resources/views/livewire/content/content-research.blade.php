<div>
<div class="mx-auto w-full max-w-6xl space-y-6">

    {{-- Flash toasts (same keys the calendar uses) --}}
    @if (session('content-status'))
        <div class="flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ session('content-status') }}
        </div>
    @endif
    @if (session('content-error'))
        <div class="flex items-center gap-2.5 rounded-xl border border-error/30 bg-error/5 px-4 py-3 text-sm font-semibold text-slate-700 dark:border-error/40 dark:text-slate-200">
            <svg class="h-4 w-4 shrink-0 text-error" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
            {{ session('content-error') }}
        </div>
    @endif

    @if (! $hasWebsite)
        <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('Select a website to see its keyword research.') }}
        </div>
    @elseif (! ($hasPlan ?? false))
        <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('Set up Content Autopilot on this website first — research starts automatically.') }}
        </div>
    @else
        @php
            /* ONE colored pill per row (difficulty); everything else muted
               text — pill soup confused clients (owner feedback 2026-07-30). */
            $intentLabels = [
                'informational' => __('Informational'),
                'commercial' => __('Commercial'),
                'transactional' => __('Transactional'),
                'navigational' => __('Navigational'),
            ];
            $difficultyChips = [
                'easy' => ['label' => __('Easy win'), 'cls' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'],
                'moderate' => ['label' => __('Moderate'), 'cls' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'],
                'hard' => ['label' => __('Hard'), 'cls' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'],
            ];
            $sourceLabels = [
                'gap' => __('found on competitors'),
                'own' => __('you already rank for this'),
                'chosen' => __('your pick'),
            ];
        @endphp

        {{-- ── Hero: always-on research + weekly counter ─────────────── --}}
        <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:flex-row sm:items-center dark:border-slate-800 dark:bg-slate-900">
            <x-nodus state="{{ $researching ? 'searching' : 'idle' }}" :size="64" class="shrink-0 text-orange-500"/>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Keyword ideas') }}</h2>
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Always on') }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('We keep researching what your audience searches for — new keywords are vetted for relevance and added to your library automatically.') }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <div class="rounded-xl border border-slate-100 px-4 py-2.5 text-center dark:border-slate-800">
                    <div class="text-2xl font-extrabold tracking-tight text-orange-600">{{ number_format($newThisWeek) }}</div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('New this week') }}</div>
                </div>
                <div class="rounded-xl border border-slate-100 px-4 py-2.5 text-center dark:border-slate-800">
                    <div class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($totalLibrary) }}</div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('In your library') }}</div>
                </div>
            </div>
        </div>

        @if ($researching && $totalLibrary === 0)
            {{-- First research run still in flight — live checklist. --}}
            <x-nodus.state state="analyzing" :size="88" wire:poll.8s
                :title="__('Researching your keywords')"
                :message="__('We\'re studying your market and your competitors. This takes a few minutes — keywords appear here as soon as they\'re vetted.')">
                @if (! empty($researchStatus))
                    <ul class="mx-auto max-w-xs space-y-1.5 text-start text-xs text-slate-500 dark:text-slate-400">
                        @foreach ($researchStatus as $srcLine)
                            <li class="flex items-center gap-2" wire:key="rs-{{ $loop->index }}">
                                @if ($srcLine['done'] ?? false)
                                    <svg class="h-3.5 w-3.5 shrink-0 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                @else
                                    <svg class="h-3.5 w-3.5 shrink-0 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                @endif
                                {{ $srcLine['label'] ?? '' }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-nodus.state>
        @endif

        {{-- ── Striking distance (client's own Search Console) ───────── --}}
        @if (! empty($striking))
            <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25"/></svg>
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Almost on page 1') }}</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Google already shows your site for these searches — one strong article can push them onto page 1.') }}</p>
                    </div>
                </div>
                <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($striking as $row)
                        <div class="flex flex-wrap items-center gap-3 py-2.5" wire:key="sd-{{ $loop->index }}">
                            <div class="min-w-0 flex-1">
                                <span class="break-words text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $row['query'] }}</span>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                                    <span>{{ __('position :p', ['p' => $row['position']]) }}</span>
                                    <span>· {{ __(':n impressions in 90 days', ['n' => number_format($row['impressions'])]) }}</span>
                                </div>
                            </div>
                            @if ($row['planned'])
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    {{ __('In your calendar') }}
                                </span>
                            @else
                                <button type="button" wire:click="addToCalendar(@js($row['query']))"
                                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-700 hover:border-orange-300 hover:text-orange-700 dark:border-slate-700 dark:text-slate-200 dark:hover:text-orange-300">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                    {{ __('Add to calendar') }}
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Main feed ─────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4 dark:border-slate-800">
                <div class="relative min-w-0 flex-1">
                    <svg class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search keywords…') }}"
                        class="w-full rounded-xl border border-slate-200 bg-white py-2 pe-3 ps-9 text-sm text-slate-700 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                </div>
                <select wire:model.live="intent" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <option value="all">{{ __('All intents') }}</option>
                    <option value="informational">{{ __('Informational') }}</option>
                    <option value="commercial">{{ __('Commercial') }}</option>
                    <option value="transactional">{{ __('Transactional') }}</option>
                    <option value="navigational">{{ __('Navigational') }}</option>
                </select>
                <select wire:model.live="difficulty" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <option value="all">{{ __('Any difficulty') }}</option>
                    <option value="easy">{{ __('Easy wins') }}</option>
                    <option value="moderate">{{ __('Moderate') }}</option>
                    <option value="hard">{{ __('Hard') }}</option>
                </select>
                <select wire:model.live="sort" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                    <option value="volume">{{ __('Most searched') }}</option>
                    <option value="newest">{{ __('Newest first') }}</option>
                    <option value="easiest">{{ __('Easiest first') }}</option>
                </select>
            </div>

            @if (empty($rows))
                <div class="m-4 rounded-xl border border-dashed border-slate-200 p-10 text-center dark:border-slate-700">
                    @if ($totalLibrary === 0)
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('Your keyword library is filling up') }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Research is running — vetted keywords will appear here shortly.') }}</p>
                    @else
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('No keywords match your filters') }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Try a different search or reset the filters.') }}</p>
                    @endif
                </div>
            @else
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($rows as $row)
                        <div class="flex flex-wrap items-center gap-3 px-4 py-3.5 transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/30" wire:key="kw-{{ md5($row['keyword']) }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="break-words text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $row['keyword'] }}</span>
                                    @if ($row['new'])
                                        <span class="rounded-full bg-orange-100 px-1.5 py-px text-[10px] font-bold uppercase tracking-wide text-orange-700 dark:bg-orange-950/60 dark:text-orange-300">{{ __('New') }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    @php $diff = $difficultyChips[$row['difficulty']] ?? $difficultyChips['moderate']; @endphp
                                    <span class="rounded-full px-2 py-px font-semibold {{ $diff['cls'] }}">{{ $diff['label'] }}</span>
                                    @if ($row['volume'])
                                        <span class="font-semibold text-slate-600 dark:text-slate-300">~{{ number_format($row['volume']) }}/{{ __('mo') }}</span>
                                    @endif
                                    @php
                                        $meta = array_filter([
                                            $row['intent'] ? ($intentLabels[$row['intent']] ?? null) : null,
                                            $sourceLabels[$row['type']] ?? null,
                                        ]);
                                    @endphp
                                    @if ($meta !== [])
                                        <span>{{ implode(' · ', $meta) }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($row['planned'])
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                        {{ __('In your calendar') }}
                                    </span>
                                @else
                                    <button type="button" wire:click="addToCalendar(@js($row['keyword']), @js($row['volume']))" wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:border-orange-300 hover:text-orange-700 dark:border-slate-700 dark:text-slate-200 dark:hover:text-orange-300">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                        {{ __('Add to calendar') }}
                                    </button>
                                    <button type="button" wire:click="addAndWrite(@js($row['keyword']), @js($row['volume']))" wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1 rounded-lg bg-orange-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-orange-700" title="{{ __('Write now') }}">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        {{ __('Write') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Pager --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        {{ __(':total keywords · page :page of :pages', ['total' => number_format($filteredTotal), 'page' => $feedPage, 'pages' => $lastPage]) }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" wire:click="previousPage" @disabled($feedPage <= 1)
                            class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            {{ __('Previous') }}
                        </button>
                        <button type="button" wire:click="nextPage" @disabled($feedPage >= $lastPage)
                            class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            {{ __('Next') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>

        {{-- ── Questions people ask ──────────────────────────────────── --}}
        @if (! empty($questions))
            <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Questions your audience asks') }}</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Real questions people type into Google — great article openers.') }}</p>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($questions as $q)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 py-1 pe-1.5 ps-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200" wire:key="q-{{ md5($q['keyword']) }}">
                            {{ $q['keyword'] }}
                            @if ($q['volume'])<span class="text-xs font-semibold text-orange-600">{{ number_format($q['volume']) }}/{{ __('mo') }}</span>@endif
                            @if ($q['planned'])
                                <svg class="h-3.5 w-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            @else
                                <button type="button" wire:click="addToCalendar(@js($q['keyword']), @js($q['volume']))" title="{{ __('Add to calendar') }}"
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-orange-600 text-white hover:bg-orange-700">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                </button>
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
</div>
