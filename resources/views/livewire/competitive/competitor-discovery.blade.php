<div @if($this->isPolling()) wire:poll.3000ms="poll" @endif>
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
        @if (! $website)
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Select a website to discover its competitors.') }}</p>
        @else
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[240px]">
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                        @if ($hasGsc)
                            {{ __('We’ll sample your top Search Console queries.') }}
                        @else
                            {{ __('Seed keywords (no Search Console connected)') }}
                        @endif
                    </label>
                    @unless ($hasGsc)
                        <textarea wire:model="seedsInput" rows="3"
                            placeholder="{{ __('One keyword per line — e.g. project management software') }}"
                            class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    @endunless
                </div>
                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="includeGiants" class="rounded border-slate-300">
                    {{ __('Include big platforms') }}
                </label>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="button" wire:click="discover" wire:loading.attr="disabled" @if($this->isPolling()) disabled @endif
                    class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500 disabled:opacity-50">
                    @if ($this->isPolling())
                        <x-nodus state="analyzing" :size="20" class="text-white" />
                        {{ __('Discovering…') }}
                    @else
                        {{ __('Discover competitors') }}
                    @endif
                </button>
                @if ($competitors->isNotEmpty())
                    <button type="button" wire:click="trackTop"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200">
                        {{ __('Track top competitors') }}
                    </button>
                    <button type="button" wire:click="classifyTopics" wire:loading.attr="disabled" wire:target="classifyTopics"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60 dark:border-slate-600 dark:text-slate-200"
                        title="{{ __('Classify each competitor\'s topic and whether it overlaps your niche (uses AI — runs on demand).') }}">
                        <svg wire:loading wire:target="classifyTopics" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        {{ __('Classify topics') }}
                    </button>
                    <button type="button" wire:click="export"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200">
                        {{ __('Export CSV') }}
                    </button>
                @endif
            </div>

            @if ($errorMessage)
                <p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $errorMessage }}</p>
            @endif
            @if ($notice)
                <p class="mt-3 text-sm text-amber-600 dark:text-amber-400">{{ $notice }}</p>
            @endif
            @if ($lastRun && $lastRun->status === 'completed' && ! $this->isPolling())
                <p class="mt-3 text-xs text-slate-400">{{ __('Last run scanned') }} {{ $lastRun->keywords_planned }} {{ __('keyword(s) via') }} {{ $lastRun->serp_calls_made }} {{ __('search(es)') }} · {{ $lastRun->completed_at?->diffForHumans() }}</p>
            @endif
        @endif
    </div>

    {{-- ── Organic traffic chart (DataForSEO estimate) ──────────────────────
         Self-contained inline SVG (no chart lib): the site's monthly organic
         traffic, with the top competitors overlaid faintly for context. Numbers
         are DataForSEO estimates — visits only, never a dollar figure. --}}
    @php
        $chartSeries = [];
        if (! empty($siteSeries)) {
            $chartSeries[] = ['label' => $website?->domain ?: __('Your site'), 'series' => $siteSeries, 'primary' => true];
        }
        foreach (($competitorSeries ?? []) as $dom => $s) {
            $chartSeries[] = ['label' => $dom, 'series' => $s, 'primary' => false];
        }
        $months = collect($chartSeries)->flatMap(fn ($c) => collect($c['series'])->pluck('month'))->unique()->sort()->values();
        $maxV = 1;
        foreach ($chartSeries as $c) { foreach ($c['series'] as $p) { $maxV = max($maxV, (int) $p['visits']); } }
        $W = 680; $H = 200; $padL = 10; $padR = 10; $padT = 12; $padB = 22;
        $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
        $n = max(1, $months->count() - 1);
        $mIdx = $months->flip();
        $x = fn ($i) => $padL + ($n ? $i / $n * $plotW : $plotW / 2);
        $y = fn ($v) => $padT + $plotH - ($maxV ? $v / $maxV * $plotH : 0);
        $palette = ['#94a3b8', '#cbd5e1', '#e2e8f0']; // faint slate for competitors
    @endphp
    @if ($months->isNotEmpty())
        <div class="mt-5 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Organic traffic') }}</h3>
                <span class="text-xs text-slate-400">{{ __('Estimated monthly visits') }}</span>
            </div>
            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="mt-3 w-full" style="max-height:220px" preserveAspectRatio="none" role="img" aria-label="{{ __('Organic traffic chart') }}">
                {{-- baseline --}}
                <line x1="{{ $padL }}" y1="{{ $padT + $plotH }}" x2="{{ $W - $padR }}" y2="{{ $padT + $plotH }}" stroke="#e5e7eb" stroke-width="1" />
                @foreach (array_reverse($chartSeries) as $ci => $c)
                    @php
                        $pts = [];
                        foreach ($c['series'] as $p) {
                            if (! isset($mIdx[$p['month']])) { continue; }
                            $pts[] = $x($mIdx[$p['month']]).','.round($y((int) $p['visits']), 1);
                        }
                        $stroke = $c['primary'] ? '#ea580c' : ($palette[($ci) % count($palette)] ?? '#cbd5e1');
                    @endphp
                    @if (count($pts) > 1)
                        <polyline fill="none" stroke="{{ $stroke }}" stroke-width="{{ $c['primary'] ? 2.5 : 1.5 }}" stroke-linejoin="round" stroke-linecap="round" points="{{ implode(' ', $pts) }}" opacity="{{ $c['primary'] ? 1 : 0.7 }}" />
                    @elseif (count($pts) === 1)
                        <circle cx="{{ explode(',', $pts[0])[0] }}" cy="{{ explode(',', $pts[0])[1] }}" r="3" fill="{{ $stroke }}" />
                    @endif
                @endforeach
            </svg>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400">
                <span>{{ $months->first() }}</span>
                <span>{{ $months->last() }}</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-3 text-xs">
                @foreach ($chartSeries as $c)
                    <span class="inline-flex items-center gap-1.5 {{ $c['primary'] ? 'font-semibold text-slate-700 dark:text-slate-200' : 'text-slate-400' }}">
                        <span class="inline-block h-2 w-2 rounded-full" style="background: {{ $c['primary'] ? '#ea580c' : '#94a3b8' }}"></span>
                        {{ $c['label'] }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    @if ($competitors->isNotEmpty())
        {{-- While an opt-in topical classification runs, poll so the Topic column
             fills in without a manual refresh. --}}
        <div @if ($topicPending) wire:poll.5s="refreshTopics" @endif class="mt-5 overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900/50">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-4 py-3">{{ __('Competitor') }}</th>
                        <th class="px-4 py-3">{{ __('Score') }}</th>
                        <th class="px-4 py-3">{{ __('Seen in') }}</th>
                        <th class="px-4 py-3">{{ __('Avg position') }}</th>
                        <th class="px-4 py-3" title="{{ __('Referring domains (DataForSEO)') }}">{{ __('Ref. domains') }}</th>
                        <th class="px-4 py-3" title="{{ __('Domain Authority (Moz)') }}">{{ __('DA') }}</th>
                        <th class="px-4 py-3" title="{{ __('Page Authority (Moz)') }}">{{ __('PA') }}</th>
                        <th class="px-4 py-3">{{ __('Topic') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                    @foreach ($competitors as $c)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">
                                {{ $c->competitor_domain }}
                                @if (is_array($c->sample_keywords) && $c->sample_keywords !== [])
                                    <span class="block text-xs font-normal text-slate-400" title="{{ implode(', ', $c->sample_keywords) }}">{{ \Illuminate\Support\Str::limit(implode(', ', $c->sample_keywords), 60) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-orange-50 px-2 py-0.5 text-xs font-semibold text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">{{ (int) $c->score }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->appearances }} / {{ $c->keywords_sampled }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->avg_position !== null ? number_format($c->avg_position, 1) : '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->referring_domains !== null ? number_format($c->referring_domains) : '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->domain_authority ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->page_authority ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($c->topic)
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-200">{{ $c->topic }}</span>
                                @else
                                    <span class="text-slate-300 dark:text-slate-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
