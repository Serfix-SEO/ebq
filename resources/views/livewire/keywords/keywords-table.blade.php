{{-- While the keyword-ideas fallback is shown, keep re-checking: fast while
     enrichment is still running, then slow so that when the next GSC sync
     brings real keywords this page auto-switches to them (needsFallback turns
     false → suggestions null → the poll stops and the real GSC table shows).
     No poll once real GSC keywords already drive the page. --}}
<div @if (($suggestions['status'] ?? null) === 'processing') wire:poll.10s
     @elseif (($suggestions['status'] ?? null) === 'ready') wire:poll.60s @endif>
    {{-- Fallback keyword suggestions: shown when this site has no usable GSC
         data (not connected / no rows / only scrap auth-nav queries). Sourced
         from the Site Explorer enrichment — same pipeline as the report. --}}
    @if ($suggestions !== null && ($suggestions['status'] ?? '') !== 'unavailable')
        @php
            $sugFmt = fn ($v) => is_numeric($v) ? number_format((int) $v) : '—';
            $sugCpc = fn ($v) => ($v ?? null) !== null ? '$'.number_format((float) $v, 2) : '—';
            $oppSrc = $suggestions['opportunity_source'] ?? null;
            $sugDomain = $suggestions['domain'] ?? '';
            $sugPreview = 12;
            $exploreUrl = fn ($d) => $d ? route('keyword-research.index', ['tab' => 'ideas', 'url' => 'https://'.$d]) : null;
            $sugTables = array_values(array_filter([
                ! empty($suggestions['keywords']) ? [
                    'title' => __('Keywords this site can rank for'),
                    'badge' => __('Estimated'),
                    'badge_title' => __('Estimated from your site content via our keyword planner'),
                    'rows' => $suggestions['keywords'],
                    'explore' => $exploreUrl($sugDomain),
                ] : null,
                ! empty($suggestions['opportunities']) ? [
                    'title' => __('Keyword opportunities'),
                    'badge' => $oppSrc ? __('From :d', ['d' => $oppSrc]) : __('From similar sites'),
                    'badge_title' => __('Keywords a similar, established site already ranks for'),
                    'rows' => $suggestions['opportunities'],
                    'explore' => $exploreUrl($oppSrc ?: $sugDomain),
                ] : null,
            ]));
        @endphp

        <section class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            {{-- Header: title + subtitle, with the Keyword Gap CTA raised up
                 here as the primary action (prominent solid button). --}}
            <div class="flex flex-col gap-4 border-b border-slate-100 p-5 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-amber-500 text-white shadow-sm">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" /></svg>
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ __('Keyword ideas for your site') }}</h2>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                            {{ __("Not enough Search Console data yet — here are real keyword ideas from your site's content and what similar sites rank for.") }}
                        </p>
                    </div>
                </div>
                <a href="{{ route('keyword-gap.index') }}" wire:navigate
                   class="group inline-flex flex-none items-center justify-center gap-2 rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500/40 dark:focus:ring-orange-400/40">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    {{ __('Go deeper with a Keyword Gap analysis') }}
                    <svg class="h-4 w-4 transition group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                </a>
            </div>

            @if (($suggestions['status'] ?? '') === 'processing')
                <div class="p-5">
                    <div class="flex items-center gap-3 text-sm font-medium text-slate-600 dark:text-slate-300">
                        <span class="h-4 w-4 flex-none animate-spin rounded-full border-2 border-slate-200 border-t-orange-500 dark:border-slate-700"></span>
                        {{ __('Finding keyword ideas for your site — this updates automatically.') }}
                    </div>
                    <div class="mt-4 space-y-2" aria-hidden="true">
                        @for ($i = 0; $i < 6; $i++)
                            <div class="flex items-center gap-4">
                                <div class="h-8 flex-1 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" style="animation-delay: {{ $i * 80 }}ms"></div>
                                <div class="h-8 w-16 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" style="animation-delay: {{ $i * 80 }}ms"></div>
                                <div class="h-8 w-16 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" style="animation-delay: {{ $i * 80 }}ms"></div>
                            </div>
                        @endfor
                    </div>
                </div>
            @else
                @foreach ($sugTables as $tbl)
                    <div @class(['border-t border-slate-100 dark:border-slate-800' => ! $loop->first])>
                        <div class="flex items-center justify-between gap-2 px-5 pb-2 pt-4">
                            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tbl['title'] }}</h3>
                            <span title="{{ $tbl['badge_title'] }}"
                                  class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 ring-1 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-800">
                                <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm11.378-3.917c-.89-.777-2.366-.777-3.255 0a.75.75 0 01-.988-1.129c1.454-1.272 3.776-1.272 5.23 0 1.513 1.324 1.513 3.518 0 4.842a3.75 3.75 0 01-.837.552c-.676.328-1.028.774-1.028 1.152v.75a.75.75 0 01-1.5 0v-.75c0-1.279 1.06-2.107 1.875-2.502.182-.088.351-.199.503-.331.83-.727.83-1.857 0-2.584zM12 18a.75.75 0 100-1.5.75.75 0 000 1.5z" clip-rule="evenodd" /></svg>
                                {{ $tbl['badge'] }}
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/70 dark:text-slate-400">
                                        <th class="px-5 py-2 text-left font-semibold">{{ __('Keyword') }}</th>
                                        <th class="px-3 py-2 text-right font-semibold">{{ __('Volume') }}</th>
                                        <th class="px-5 py-2 text-right font-semibold">{{ __('CPC') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach (array_slice($tbl['rows'], 0, $sugPreview) as $row)
                                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                            <td class="px-5 py-2.5 font-medium text-slate-800 dark:text-slate-200">{{ $row['keyword'] ?? '' }}</td>
                                            <td class="px-3 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $sugFmt($row['volume'] ?? null) }}</td>
                                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $sugCpc($row['cpc'] ?? null) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{-- Explore-all: hands off to the full Keyword Research finder
                             (website mode, auto-run) → paging, sort, filters, term
                             groups and AI clustering over the COMPLETE keyword set. --}}
                        @if (! empty($tbl['explore']))
                            <a href="{{ $tbl['explore'] }}" wire:navigate
                               class="flex items-center justify-between gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3 text-sm font-semibold text-orange-700 transition hover:bg-orange-50 dark:border-slate-800 dark:bg-slate-800/40 dark:text-orange-300 dark:hover:bg-orange-500/10">
                                <span>
                                    {{ __('Explore all keyword ideas') }}
                                    <span class="ms-1 font-normal text-slate-500 dark:text-slate-400">{{ __('— sort, filter, group & AI-cluster') }}</span>
                                </span>
                                <svg class="h-4 w-4 flex-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                            </a>
                        @endif
                    </div>
                @endforeach
            @endif
        </section>
    @endif

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 items-center gap-2">
            <div class="relative flex-1 sm:max-w-xs">
                <svg class="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('Search keywords…') }}"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white ps-8 pe-2.5 text-xs placeholder-slate-400 shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:placeholder-slate-500" />
            </div>
            <select wire:model.live="device"
                class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                <option value="">{{ __('All devices') }}</option>
                <option value="DESKTOP">{{ __('Desktop') }}</option>
                <option value="MOBILE">{{ __('Mobile') }}</option>
                <option value="TABLET">{{ __('Tablet') }}</option>
            </select>
            <input wire:model.live="from" type="date" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
            <input wire:model.live="to" type="date" class="h-8 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
            <livewire:dashboard.country-filter />
        </div>

        <div class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-800">
            <button wire:click="$set('view', 'aggregated')"
                @class([
                    'h-7 rounded px-2.5 text-xs font-semibold transition',
                    'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-slate-100' => $view === 'aggregated',
                    'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $view !== 'aggregated',
                ])>
                {{ __('Aggregated') }}
            </button>
            <button wire:click="$set('view', 'daily')"
                @class([
                    'h-7 rounded px-2.5 text-xs font-semibold transition',
                    'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-slate-100' => $view === 'daily',
                    'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $view !== 'daily',
                ])>
                {{ __('By Date') }}
            </button>
        </div>
    </div>

    @if ($rows instanceof \Illuminate\Contracts\Pagination\Paginator && $rows->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
                            @if ($view === 'daily')
                                <x-sort-header column="date" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Date') }}</x-sort-header>
                            @endif
                            <x-sort-header column="query" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Keyword') }}</x-sort-header>
                            <x-sort-header column="clicks" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('Clicks') }}</x-sort-header>
                            <x-sort-header column="impressions" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('Impressions') }}</x-sort-header>
                            <x-sort-header column="ctr" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('CTR') }}</x-sort-header>
                            <x-sort-header column="position" :sortBy="$sortBy" :sortDir="$sortDir" align="right">{{ __('Position') }}</x-sort-header>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400" title="{{ __('Global monthly search volume') }}">{{ __('Volume') }}</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400" title="{{ __('Projected monthly organic value at your current position (volume × CTR × CPC)') }}">{{ __('Value/mo') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($rows as $row)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                @if ($view === 'daily')
                                    <td class="whitespace-nowrap px-4 py-2.5 text-slate-500 dark:text-slate-400">{{ format_user_date($row->date instanceof \Carbon\CarbonInterface ? $row->date->toDateString() : (is_string($row->date) ? $row->date : ''), 'M d, Y') ?: $row->date }}</td>
                                @endif
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-900 dark:text-slate-100">
                                    <a href="{{ route('keywords.show', ['query' => rawurlencode((string) $row->query)]) }}" wire:navigate class="text-orange-600 hover:underline dark:text-orange-400">{{ $row->query }}</a>
                                    @php $qKey = mb_strtolower((string) $row->query); @endphp
                                    <x-keyword-language :language="($languages ?? [])[$qKey] ?? null" />
                                    @if (isset($cannibalized[$qKey]))
                                        <span class="ms-1.5 inline-flex items-center rounded-full bg-amber-50 px-1.5 py-px text-[10px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400" title="{{ __('Multiple pages rank for this query') }}">{{ __('cannibalized') }}</span>
                                    @endif
                                    @if (isset($tracked[$qKey]))
                                        <span class="ms-1 inline-flex items-center rounded-full bg-orange-50 px-1.5 py-px text-[10px] font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-400" title="{{ __('Tracked in Rank Tracking') }}">{{ __('tracked') }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->clicks) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->impressions) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ number_format($row->ctr * 100, 1) }}%</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <span @class([
                                        'inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $row->position <= 3,
                                        'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => $row->position > 3 && $row->position <= 10,
                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $row->position > 10 && $row->position <= 20,
                                        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $row->position > 20,
                                    ])>{{ number_format($row->position, 1) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-300">
                                    @php
                                        $ke = ($keMetrics ?? [])[\App\Models\KeywordMetric::hashKeyword((string) $row->query)] ?? null;
                                        $_keTitle = '';
                                        $_trend = 'unknown';
                                        if ($ke) {
                                            $_keTitle = __('Updated ').$ke->fetched_at->diffForHumans();
                                            if ($ke->cpc !== null) {
                                                $_keTitle .= __(' · CPC ').($ke->currency ?: 'USD').' '.number_format((float) $ke->cpc, 2);
                                            }
                                            $_trend = $ke->trend_class;
                                        }
                                    @endphp
                                    @if ($ke && $ke->search_volume !== null)
                                        <span class="inline-flex items-center gap-1" title="{{ $_keTitle }}">
                                            {{ number_format($ke->search_volume) }}
                                            @if ($_trend === 'rising')
                                                <span class="text-[9px] font-bold text-emerald-600 dark:text-emerald-400" title="{{ __('Trend: rising') }}">↑</span>
                                            @elseif ($_trend === 'falling')
                                                <span class="text-[9px] font-bold text-rose-600 dark:text-rose-400" title="{{ __('Trend: falling') }}">↓</span>
                                            @elseif ($_trend === 'seasonal')
                                                <span class="text-[9px] font-bold text-amber-600 dark:text-amber-400" title="{{ __('Seasonal pattern') }}">◐</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">
                                    @php
                                        $_keValue = $ke ? \App\Services\KeywordValueCalculator::projectedMonthlyValue($ke->search_volume, (float) $row->position, $ke->cpc) : null;
                                    @endphp
                                    @if ($_keValue !== null && $_keValue > 0)
                                        <span class="font-semibold text-slate-900 dark:text-slate-100">${{ number_format($_keValue, 0) }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    @elseif ($suggestions === null || ($suggestions['status'] ?? '') === 'unavailable')
        <div class="flex flex-col items-center justify-center rounded-xl border border-slate-200 bg-white px-6 py-16 dark:border-slate-800 dark:bg-slate-900">
            <svg class="h-12 w-12 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('No keyword data yet') }}</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('Data will appear after the daily sync runs.') }}</p>
        </div>
    @endif
</div>
