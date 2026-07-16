@php
    $buckets = [
        'missing' => ['label' => __('Missing'), 'hint' => __('They rank, you don’t')],
        'weak' => ['label' => __('Weak'), 'hint' => __('You rank, but poorly')],
        'strength' => ['label' => __('Strengths'), 'hint' => __('You lead')],
        'shared' => ['label' => __('Shared'), 'hint' => __('Both target it')],
    ];
    $summary = $analysis?->summary ?? [];
    $hasGsc = (bool) $website?->hasGsc();
    $verified = $analysis && $analysis->verify_status === 'completed';
    // Show position columns once we have positions — from GSC or verification.
    $showPositions = $hasGsc || $verified;
    // After verification a no-GSC site can split weak/strength too — but keep
    // the Shared tab while unverified shared rows remain (verification is
    // budgeted per pass, so a big gap re-buckets progressively; hiding the tab
    // stranded the not-yet-verified rows).
    $sharedLeft = (int) ($summary['shared'] ?? 0) > 0;
    $visibleTabs = $showPositions
        ? array_merge(['missing', 'weak', 'strength'], $sharedLeft ? ['shared'] : [])
        : ['missing', 'shared'];
@endphp

<div @if($this->isPolling() || $this->isVerifying() || $this->isFinding()) wire:poll.3000ms="poll" @endif>
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
        {{-- Target website: defaults to the current site, editable to any URL.
             Own site → competitors load from its Site Explorer report; a foreign
             URL → manual add + a link to discover competitors. --}}
        <div class="mb-4">
            <label class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Analyze') }}</label>
            <div class="mt-1.5 flex items-center gap-2">
                <div class="relative flex-1 sm:max-w-md">
                    <svg class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918" /></svg>
                    <input type="text" wire:model.live.debounce.500ms="targetUrl" placeholder="example.com"
                           class="h-10 w-full rounded-lg border border-slate-300 bg-white ps-9 pe-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                </div>
                {{-- Foreign target with no known competitors → discover them inline
                     (no page navigation). --}}
                @if ($targetIsForeign && empty($suggested) && $findStatus !== 'done')
                    <button type="button" wire:click="findCompetitors" wire:loading.attr="disabled" wire:target="findCompetitors"
                            @disabled($this->isFinding())
                            class="inline-flex h-10 flex-none items-center justify-center gap-1.5 rounded-lg bg-orange-600 px-3.5 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-60">
                        @if ($this->isFinding())
                            <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            {{ __('Finding…') }}
                        @else
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                            {{ __('Find competitors') }}
                        @endif
                    </button>
                @endif
            </div>
        </div>
        @if ($targetDomain === '')
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Enter a website URL above to run a gap analysis.') }}</p>
        @elseif ($this->isPolling())
            {{-- Collecting: the picker collapses into a live progress teaser so
                 it's obvious work is happening and where the results will land. --}}
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 flex-none items-center justify-center rounded-full bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300">
                    <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ __('Analysing the keyword gap…') }}</h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ __('We’re discovering the full keyword footprint of each site on our keyword servers, then diffing them against yours. Results appear right here — usually within a minute.') }}</p>

                    <ul class="mt-4 space-y-2">
                        @foreach ($progress as $src)
                            <li class="flex items-center gap-2.5 text-sm">
                                @if ($src['state'] === 'running')
                                    <svg class="h-4 w-4 flex-none animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                @elseif ($src['state'] === 'failed')
                                    <svg class="h-4 w-4 flex-none text-rose-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                                @else
                                    <svg class="h-4 w-4 flex-none text-emerald-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                @endif
                                <span class="truncate font-medium text-slate-700 dark:text-slate-200">{{ $src['domain'] }}</span>
                                <span class="text-xs text-slate-400">
                                    @if ($src['role'] === 'ours') · {{ __('your site') }} @endif
                                    {{-- Internal cached-vs-fetched distinction is never surfaced
                                         to clients (see client-facing copy rules in infra/main.md). --}}
                                    @if (in_array($src['state'], ['cached', 'done'], true)) · {{ __('keywords collected') }}
                                    @elseif ($src['state'] === 'failed') · {{ __('unavailable') }}
                                    @else · {{ __('discovering keywords…') }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($analysis)
                        <p class="mt-3 text-xs text-slate-400">{{ $analysis->completed_requests }}/{{ $analysis->total_requests }} {{ __('sources done — then we bucket every keyword into Missing / Weak / Strengths and score the opportunities.') }}</p>
                    @endif

                    {{-- Skeleton of the incoming results table --}}
                    <div class="mt-5 space-y-2" aria-hidden="true">
                        <div class="h-8 w-full max-w-md animate-pulse rounded-lg bg-slate-100 dark:bg-slate-700/60"></div>
                        <div class="h-10 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-700/60"></div>
                        <div class="h-10 animate-pulse rounded-lg bg-slate-100 opacity-70 dark:bg-slate-700/60"></div>
                        <div class="h-10 animate-pulse rounded-lg bg-slate-100 opacity-40 dark:bg-slate-700/60"></div>
                    </div>
                </div>
            </div>
        @elseif ($analysis && $analysis->status === 'completed' && ! $editingCompetitors)
            {{-- Completed: the picker collapses into a compact summary bar so
                 the results below are the star; "Change competitors" brings
                 the picker back. --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">
                        {{ __('Gap analysis') }} · <span class="text-slate-500 dark:text-slate-400">{{ $website?->domain ?? $targetDomain }}</span>
                        <span class="text-slate-400">{{ __('vs') }}</span>
                    </p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        @foreach ((array) $analysis->competitor_urls as $domain)
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 py-0.5 pe-2.5 ps-1.5 text-xs font-medium text-slate-700 dark:border-slate-600 dark:bg-slate-700/60 dark:text-slate-200">
                                <img src="https://www.google.com/s2/favicons?domain={{ urlencode($domain) }}&sz=32" alt="" width="14" height="14" class="h-3.5 w-3.5 rounded-sm" loading="lazy" onerror="this.style.visibility='hidden'">
                                {{ $domain }}
                            </span>
                        @endforeach
                        <span class="text-xs uppercase text-slate-400">{{ $analysis->country }}</span>
                        @if ($analysis->completed_at)
                            <span class="text-xs text-slate-400">· {{ __('generated') }} {{ $analysis->completed_at->diffForHumans() }}</span>
                        @endif
                        @if ($analysis->expires_at && $analysis->expires_at->isPast())
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-800" title="{{ __('Rankings shift over time — refresh for current data.') }}">{{ __('May be outdated') }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex flex-none flex-wrap items-center gap-2 self-start sm:self-auto">
                    {{-- Saved reports for this target — reopening is free (no new lookups). --}}
                    @if ($pastAnalyses->count() > 1)
                        <select wire:change="loadAnalysis($event.target.value)"
                            class="h-8 max-w-[220px] rounded-lg border-slate-200 bg-white text-xs text-slate-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300">
                            @foreach ($pastAnalyses as $pa)
                                <option value="{{ $pa->id }}" @selected($pa->id === $analysis->id)>
                                    {{ $pa->completed_at?->format('M j, H:i') ?? __('(running)') }} · {{ count((array) $pa->competitor_urls) }} {{ __('competitors') }}{{ $pa->verified_at ? ' · '.__('verified') : '' }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                    <button type="button" wire:click="refreshAnalysis" wire:loading.attr="disabled" wire:target="refreshAnalysis"
                        title="{{ __('Re-run with the same competitors for fresh data') }}"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-700/50">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        {{ __('Refresh') }}
                    </button>
                    <button type="button" wire:click="changeCompetitors"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-700/50">
                        {{ __('Change competitors') }}
                    </button>
                </div>
            </div>
        @else
            {{-- Competitor picker — one-click suggestions from the Site
                 Explorer snapshot (free cache read, already ranked by shared
                 keywords). Manual entry covers anything not suggested.
                 Selection is CLIENT-SIDE Alpine (deferred-entangled to
                 $competitors) so toggling is instant — a server round-trip
                 per click re-rendered the whole component, including the
                 results-table queries, and felt laggy. State syncs with the
                 next Livewire request (Run / manual add). --}}
            {{-- Re-key on the suggestion set so Alpine re-inits `suggestedDomains`
                 when inline discovery / a target change repopulates suggestions —
                 otherwise already-suggested competitors also rendered as redundant
                 manual chips (stale Alpine state). --}}
            <div wire:key="gap-picker-{{ substr(md5(implode(',', array_column($suggested, 'domain'))), 0, 10) }}"
                x-data="{
                selected: $wire.entangle('competitors'),
                suggestedDomains: @js(array_column($suggested, 'domain')),
                max: {{ (int) $maxCompetitors }},
                capHit: false,
                toggle(d) {
                    const i = this.selected.indexOf(d);
                    if (i >= 0) { this.selected.splice(i, 1); this.capHit = false; return; }
                    if (this.selected.length >= this.max) { this.capHit = true; return; }
                    this.selected.push(d); this.capHit = false;
                },
            }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Pick competitors') }}
                            <span class="ms-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-slate-600 dark:bg-slate-700 dark:text-slate-300" x-text="selected.length + '/' + max"></span>
                        </p>
                        @if (! empty($suggested))
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Suggested from your Site Explorer report — ranked by keywords you already share.') }}</p>
                        @endif
                    </div>
                    <div>
                        <select wire:model="country" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                            @foreach ($countryOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if (! empty($suggested))
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($suggested as $s)
                            <button type="button" @click="toggle('{{ $s['domain'] }}')"
                                class="flex items-center gap-2.5 rounded-xl border p-3 text-start transition"
                                :class="selected.includes('{{ $s['domain'] }}')
                                    ? 'border-orange-500 bg-orange-50 ring-1 ring-orange-500 dark:border-orange-400 dark:bg-orange-500/10 dark:ring-orange-400'
                                    : 'border-slate-200 bg-white hover:border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-slate-600'">
                                <img src="https://www.google.com/s2/favicons?domain={{ urlencode($s['domain']) }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100 dark:bg-slate-800" loading="lazy" onerror="this.style.visibility='hidden'">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $s['domain'] }}</span>
                                    <span class="block text-[11px] text-slate-500 dark:text-slate-400">
                                        @if ($s['shared_keywords'] !== null){{ number_format($s['shared_keywords']) }} {{ __('shared keywords') }}@endif
                                        @if ($s['avg_position'] !== null) · {{ __('avg pos') }} {{ number_format($s['avg_position'], 1) }}@endif
                                    </span>
                                </span>
                                <span class="flex h-5 w-5 flex-none items-center justify-center rounded-full border"
                                    :class="selected.includes('{{ $s['domain'] }}')
                                        ? 'border-orange-500 bg-orange-500 text-white dark:border-orange-400 dark:bg-orange-400'
                                        : 'border-slate-300 dark:border-slate-600'">
                                    <svg x-show="selected.includes('{{ $s['domain'] }}')" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                </span>
                            </button>
                        @endforeach
                    </div>
                @elseif ($targetIsForeign && ($this->isFinding() || $findStatus === 'done'))
                    <div class="mt-3 rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-4 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-400">
                        @if ($this->isFinding())
                            <span class="inline-flex items-center gap-2">
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-orange-500"></span>
                                {{ __('Finding competitors for :d — this updates automatically.', ['d' => $targetDomain]) }}
                            </span>
                        @else
                            {{ __('No competitors found automatically — add them manually below.') }}
                        @endif
                    </div>
                @elseif (! $targetIsForeign)
                    <div class="mt-3 rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-4 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-400">
                        {{ __('No Site Explorer report for this website yet — open the') }}
                        <a href="{{ route('competitors.index') }}" class="font-semibold text-orange-600 hover:underline dark:text-orange-400">{{ __('Competitors page') }}</a>
                        {{ __('to discover competitors automatically, or add them manually below.') }}
                    </div>
                @endif

                <p x-show="capHit" x-cloak class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400" style="display:none">
                    {{ __('You can compare up to :max competitors per run — deselect one first.', ['max' => $maxCompetitors]) }}
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    {{-- Manually-added domains (not among the suggestions) as removable chips. --}}
                    <template x-for="d in selected.filter(x => ! suggestedDomains.includes(x))" :key="d">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 py-1 pe-1.5 ps-3 text-xs font-medium text-orange-800 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                            <span x-text="d"></span>
                            <button type="button" @click="toggle(d)" class="rounded-full p-0.5 hover:bg-orange-100 dark:hover:bg-orange-500/20" aria-label="{{ __('Remove') }}">
                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                            </button>
                        </span>
                    </template>
                    <div class="flex items-center gap-1.5">
                        <input type="text" wire:model="manualDomain" wire:keydown.enter="addManualCompetitor" placeholder="{{ __('Add competitor manually…') }}"
                            class="w-52 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <button type="button" wire:click="addManualCompetitor"
                            class="rounded-lg border border-slate-200 px-2.5 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Add') }}</button>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button type="button" wire:click="run" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500 disabled:opacity-50">
                        <svg wire:loading wire:target="run" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        {{ __('Run gap analysis') }}
                    </button>
                </div>
            </div>

            @unless ($hasGsc)
                <p class="mt-3 text-xs text-amber-600 dark:text-amber-400">{{ __('No Search Console connected — keywords reflect what each site’s content') }} <em>{{ __('targets') }}</em>{{ __(', not confirmed rankings. Connect Search Console to split shared keywords into Weak vs Strengths and unlock position data.') }}</p>
            @endunless
            @if ($errorMessage)
                @if (\App\Support\QuotaMessage::isQuota($errorMessage))
                    <x-quota-alert :message="$errorMessage" />
                @else
                    <p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $errorMessage }}</p>
                @endif
            @endif
            @if ($analysis?->reprocessed_at)
                <p class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">{{ __('Upgraded with your Search Console data.') }}</p>
            @endif
            @if ($trackNotice)
                <p class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">{{ $trackNotice }}</p>
            @endif
        @endif
    </div>

    @if ($analysis && $analysis->status === 'completed')
        {{-- Post-verification value banner: the live check's findings, loud and
             actionable — each chip jumps to its bucket. Dismissible; reappears
             after the next verify pass. --}}
        @php $vTotal = array_sum($verifiedCounts ?? []); @endphp
        @if ($vTotal > 0 && ! $verifyBannerDismissed && ! $this->isVerifying())
            @php
                $vStrength = (int) ($verifiedCounts['strength'] ?? 0);
                $vWeak = (int) ($verifiedCounts['weak'] ?? 0);
                $vMissing = (int) ($verifiedCounts['missing'] ?? 0);
                // Unchecked keywords still sitting in Missing — the banner counts
                // reflect only the CHECKED slice, so say so and offer to continue.
                $vRemaining = max(0, (int) ($summary['missing'] ?? 0) - $vMissing);
            @endphp
            <div class="mt-5 overflow-hidden rounded-2xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50 shadow-sm dark:border-emerald-900 dark:from-emerald-500/10 dark:to-teal-500/10">
                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-emerald-600 text-white shadow-sm">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </span>
                        <div>
                            <p class="text-base font-bold text-emerald-900 dark:text-emerald-200">
                                {{ __(':n keywords checked against Google’s live results', ['n' => number_format($vTotal)]) }}
                            </p>
                            <p class="mt-0.5 text-sm text-emerald-800 dark:text-emerald-300">
                                @if ($vStrength > 0)
                                    {{ __('Good news — you already rank well for :n of them.', ['n' => $vStrength]) }}
                                @elseif ($vWeak > 0)
                                    {{ __('You’re close on :n keywords — small pushes could win them.', ['n' => $vWeak]) }}
                                @else
                                    {{ __('These are confirmed real gaps — your competitors rank, you don’t. Prime targets.') }}
                                @endif
                                @if ($vRemaining > 0)
                                    {{ __(':n keywords haven’t been checked yet.', ['n' => number_format($vRemaining)]) }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="dismissVerifyBanner" aria-label="{{ __('Dismiss') }}"
                            class="flex-none self-start rounded-full p-1.5 text-emerald-700 transition hover:bg-emerald-100 dark:text-emerald-300 dark:hover:bg-emerald-500/20">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>
                <div class="flex flex-wrap gap-2 border-t border-emerald-100 bg-white/60 px-5 py-3 dark:border-emerald-900/50 dark:bg-transparent">
                    @foreach ([
                        ['key' => 'strength', 'count' => $vStrength, 'label' => __('Strengths — you already rank'), 'chip' => 'bg-emerald-600 text-white hover:bg-emerald-700'],
                        ['key' => 'weak', 'count' => $vWeak, 'label' => __('Weak — close to ranking'), 'chip' => 'bg-amber-500 text-white hover:bg-amber-600'],
                        ['key' => 'missing', 'count' => $vMissing, 'label' => __('Confirmed gaps'), 'chip' => 'bg-slate-700 text-white hover:bg-slate-800'],
                    ] as $c)
                        @if ($c['count'] > 0)
                            <button type="button" wire:click="setTab('{{ $c['key'] }}')"
                                    class="inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-bold shadow-sm transition {{ $c['chip'] }}">
                                <span class="rounded-full bg-white/25 px-1.5 tabular-nums">{{ number_format($c['count']) }}</span>
                                {{ $c['label'] }}
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                            </button>
                        @endif
                    @endforeach
                    {{-- Continue the check: verify the next slice of unchecked keywords. --}}
                    @if ($vRemaining > 0)
                        <button type="button" wire:click="verifyMissingMore" wire:loading.attr="disabled" wire:target="verifyMissingMore"
                                class="inline-flex items-center gap-2 rounded-full border-2 border-emerald-600 bg-white px-3.5 py-1 text-xs font-bold text-emerald-700 shadow-sm transition hover:bg-emerald-50 disabled:opacity-60 dark:border-emerald-400 dark:bg-transparent dark:text-emerald-300 dark:hover:bg-emerald-500/10">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                            {{ __('Check :n more', ['n' => number_format($vRemaining)]) }}
                        </button>
                    @endif
                </div>
            </div>
        @endif

        <div class="mt-5">
            <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-700">
                @foreach ($visibleTabs as $key)
                    <button type="button" wire:click="setTab('{{ $key }}')"
                        @class([
                            'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                            'border-orange-600 text-orange-600 dark:text-orange-400' => $tab === $key,
                            'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key,
                        ])>
                        {{ $buckets[$key]['label'] }}
                        <span class="ml-1 rounded-full bg-slate-100 px-1.5 text-xs text-slate-500 dark:bg-slate-700">{{ $summary[$key] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Live verification is available on every position-bearing tab —
                 Missing confirms the competitor really ranks; Weak/Strength/
                 Shared capture the real current positions for you AND them. --}}
            @if ($this->isVerifying())
                {{-- Prominent live-verification progress: bar + counts, can't be missed. --}}
                @php
                    $vDone = (int) $analysis->verify_done;
                    $vTotalRun = max(1, (int) $analysis->verify_total);
                    $vPct = min(100, (int) round(100 * $vDone / $vTotalRun));
                @endphp
                <div class="mt-3 rounded-xl border border-orange-200 bg-orange-50/70 p-4 dark:border-orange-500/30 dark:bg-orange-500/10">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="flex items-center gap-2.5 text-sm font-bold text-orange-900 dark:text-orange-200">
                            <svg class="h-4.5 w-4.5 h-5 w-5 animate-spin text-orange-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            {{ __('Checking Google’s live results…') }}
                        </p>
                        <span class="text-sm font-bold tabular-nums text-orange-800 dark:text-orange-300">{{ number_format($vDone) }} / {{ number_format($vTotalRun) }} · {{ $vPct }}%</span>
                    </div>
                    <div class="mt-2.5 h-2.5 overflow-hidden rounded-full bg-orange-100 dark:bg-orange-500/20">
                        <div class="h-full rounded-full bg-orange-600 transition-all duration-500" style="width: {{ max(3, $vPct) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-orange-800/80 dark:text-orange-300/80">{{ __('Each keyword is checked against the real search results — positions land in the table as they’re confirmed.') }}</p>
                    @if ($verifyNotice)
                        <p class="mt-2 flex items-start gap-1.5 text-xs font-medium text-orange-900 dark:text-orange-200">
                            <svg class="mt-0.5 h-3.5 w-3.5 flex-none" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                            <span>{{ $verifyNotice }}</span>
                        </p>
                    @endif
                </div>
            @else
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-900/40">
                    <span class="flex items-center gap-2">
                        <button type="button" wire:click="verifyRankings"
                            class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700 dark:bg-slate-200 dark:text-slate-900">
                            {{ __('Verify with live rankings') }}
                        </button>
                        @if (($serpRemaining ?? null) !== null)
                            <span class="text-[11px] tabular-nums {{ $serpRemaining <= 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-slate-400' }}">
                                {{ $serpRemaining <= 0 ? __('No live checks left this month — verification can still use already-cached results') : __(':n live checks left this month', ['n' => number_format($serpRemaining)]) }}
                            </span>
                        @endif
                    </span>
                    <span class="text-[11px] text-slate-400">
                        @if ($tab === 'missing')
                            {{ __('Confirms the competitor really ranks (top 10) and captures real positions.') }}
                        @else
                            {{ __('Checks Google right now and captures the real current positions — yours and the competitor’s.') }}
                        @endif
                    </span>
                </div>
            @endif
            @if ($verified && $analysis->verify_error)
                @if (\App\Support\QuotaMessage::isQuota($analysis->verify_error))
                    <x-quota-alert :message="$analysis->verify_error.' ('.$analysis->verify_done.'/'.$analysis->verify_total.' '.__('checked so far — the rest resume once your limit resets or you upgrade.').')'" />
                @else
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ __('Partial:') }} {{ $analysis->verify_error }} ({{ $analysis->verify_done }}/{{ $analysis->verify_total }} {{ __('checked.') }})</p>
                @endif
            @endif
            @if ($verifyNotice)
                <p class="mt-2 text-xs text-slate-500">{{ $verifyNotice }}</p>
            @endif

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-400">{{ $buckets[$tab]['hint'] ?? '' }} · {{ $total }} {{ __('keyword(s)') }}</p>
                <div class="flex items-center gap-3">
                    @if ($tab === 'missing' && $verified)
                        <label class="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                            <input type="checkbox" wire:model.live="confirmedOnly" class="rounded border-slate-300">
                            {{ __('Confirmed only') }}
                        </label>
                    @endif
                    <input type="text" wire:model.live.debounce.400ms="filterText" placeholder="{{ __('Filter keywords…') }}"
                        class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                </div>
            </div>

            <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/50">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <th class="px-4 py-3">{{ __('Keyword') }}</th>
                            <th class="px-4 py-3">{{ __('Opportunity') }}</th>
                            <th class="px-4 py-3">{{ __('Volume') }}</th>
                            <th class="px-4 py-3">{{ __('CPC') }}</th>
                            @if ($showPositions)<th class="px-4 py-3">{{ __('Your position') }}</th>@endif
                            <th class="px-4 py-3">{{ __('Competitors') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{{ $row->keyword }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $s = $row->opportunity_score;
                                        $cls = $s === null ? 'bg-slate-100 text-slate-500' : ($s >= 70 ? 'bg-emerald-100 text-emerald-700' : ($s >= 40 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'));
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $cls }}"
                                        title="{{ is_array($row->score_components) ? json_encode($row->score_components) : '' }}">{{ $s ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row->search_volume !== null ? number_format($row->search_volume) : '—' }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row->cpc !== null ? '$'.number_format($row->cpc, 2) : '—' }}</td>
                                @if ($showPositions)<td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row->our_position !== null ? number_format($row->our_position, 1) : '—' }}</td>@endif
                                <td class="px-4 py-3 text-xs text-slate-500">
                                    @if ($row->verified_at !== null)
                                        {{-- Live-checked: plain-language, comparative. --}}
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            @if ($row->competitor_position !== null)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                                                      title="{{ __('Live Google check') }}">
                                                    {{ $row->competitor_domain ?: __('Competitor') }} {{ __('ranks') }} #{{ $row->competitor_position }}
                                                </span>
                                            @else
                                                <span class="text-slate-400">{{ __('No competitor in Google’s top 10') }}</span>
                                            @endif
                                            @if ($row->our_position !== null)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                                                    {{ __('You rank') }} #{{ (int) round($row->our_position) }}
                                                </span>
                                            @elseif ($row->competitor_position !== null)
                                                <span class="text-slate-400">· {{ __('you’re not in the top 10') }}</span>
                                            @endif
                                        </div>
                                    @else
                                        {{ is_array($row->competitor_domains) ? implode(', ', $row->competitor_domains) : '' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        {{-- Primary: target this gap keyword → track its rank. --}}
                                        <button type="button" wire:click="track(@js($row->keyword))" wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-1 rounded-md border border-orange-200 bg-orange-50 px-2 py-1 text-xs font-semibold text-orange-700 transition hover:bg-orange-100 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300 dark:hover:bg-orange-500/20">
                                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                            {{ __('Track') }}
                                        </button>
                                        {{-- Secondary: expand into related keyword ideas. --}}
                                        <button type="button" wire:click="sendToIdeas('{{ $row->id }}')"
                                            class="rounded-md border border-slate-200 px-2 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700/50"
                                            title="{{ __('Expand this keyword into related ideas') }}">
                                            {{ __('Find similar') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $showPositions ? 7 : 6 }}" class="px-4 py-8 text-center text-sm text-slate-400">{{ __('No keywords in this bucket.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($totalPages > 1)
                <div class="mt-3 flex items-center justify-center gap-2 text-sm">
                    <button type="button" wire:click="setPage({{ $page - 1 }})" @disabled($page <= 1) class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40">{{ __('Prev') }}</button>
                    <span class="text-slate-500">{{ __('Page') }} {{ $page }} {{ __('of') }} {{ $totalPages }}</span>
                    <button type="button" wire:click="setPage({{ $page + 1 }})" @disabled($page >= $totalPages) class="rounded border border-slate-300 px-2 py-1 disabled:opacity-40">{{ __('Next') }}</button>
                </div>
            @endif
        </div>
    @endif
</div>
