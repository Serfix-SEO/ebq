@php
    $tabs = [
        ['key' => 'cannibalization',  'label' => __('Cannibalizations'),        'count' => $counts['cannibalizations'],             'tone' => 'amber',   'hint' => __('Queries split across pages')],
        ['key' => 'striking_distance','label' => __('Striking distance'),       'count' => $counts['striking_distance'],            'tone' => 'orange',  'hint' => __('Pos 5–20, low CTR')],
        ['key' => 'content_decay',    'label' => __('Content decay'),           'count' => $counts['content_decay'],                'tone' => 'slate',   'hint' => __('Losing clicks 28d/28d')],
        ['key' => 'quick_wins',       'label' => __('Quick wins'),              'count' => $counts['quick_wins'] ?? null,           'tone' => 'emerald', 'hint' => __('Low-competition keywords you aren\'t winning')],
        ['key' => 'audit_performance','label' => __('Audit vs traffic'),        'count' => null,                                     'tone' => 'rose',    'hint' => __('Poor CWV, high impressions')],
    ];
    $activeLabel = collect($tabs)->firstWhere('key', $tab)['label'] ?? '';
@endphp
<div class="space-y-4" wire:key="insights-{{ $websiteId }}">
    {{-- Category tiles --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{{ __('Action lists · last 28 days') }}</p>
            <div class="flex items-center gap-3">
                <livewire:dashboard.country-filter />
                <div wire:loading.flex wire:target="setTab,onCountryChanged" class="items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400" role="status" aria-live="polite">
                    <svg class="h-3 w-3 animate-spin text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75"></path></svg>
                    {{ __('Loading…') }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-7" role="tablist" aria-label="{{ __('Insight categories') }}">
            @foreach ($tabs as $t)
                @php $active = $tab === $t['key']; @endphp
                <button type="button" wire:click="setTab('{{ $t['key'] }}')"
                    role="tab"
                    aria-selected="{{ $active ? 'true' : 'false' }}"
                    aria-controls="insights-panel-content"
                    id="insights-tab-{{ $t['key'] }}"
                    tabindex="{{ $active ? 0 : -1 }}"
                    @class([
                        'flex flex-col items-start rounded-lg border px-3 py-2.5 text-left transition focus:outline-none focus:ring-2 focus:ring-orange-500/40 focus-visible:ring-2',
                        'border-orange-300 bg-orange-50 dark:border-orange-500/40 dark:bg-orange-500/10' => $active,
                        'border-slate-200 bg-white hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:bg-slate-800' => ! $active,
                    ])>
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $t['label'] }}</span>
                    <span @class([
                        'mt-1 text-xl font-bold tabular-nums',
                        'text-amber-600 dark:text-amber-400' => $t['tone'] === 'amber',
                        'text-orange-600 dark:text-orange-400' => $t['tone'] === 'orange',
                        'text-red-600 dark:text-red-400' => $t['tone'] === 'red',
                        'text-slate-700 dark:text-slate-200' => $t['tone'] === 'slate',
                        'text-rose-600 dark:text-rose-400' => $t['tone'] === 'rose',
                        'text-emerald-600 dark:text-emerald-400' => $t['tone'] === 'emerald',
                    ])>{{ $t['count'] === null ? __('View') : number_format($t['count']) }}</span>
                    <span class="mt-0.5 truncate text-[10px] text-slate-400 dark:text-slate-500">{{ $t['hint'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Content --}}
    <div id="insights-panel-content" role="tabpanel" aria-labelledby="insights-tab-{{ $tab }}" aria-label="{{ __(':label details', ['label' => $activeLabel]) }}">
        {{-- Skeleton while switching tabs --}}
        <div wire:loading.flex wire:target="setTab" class="flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="h-4 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-700"></div>
            <div class="h-3 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-700"></div>
            <div class="mt-2 space-y-2">
                @for ($i = 0; $i < 4; $i++)
                    <div class="flex gap-3">
                        <div class="h-3 flex-1 animate-pulse rounded bg-slate-100 dark:bg-slate-800"></div>
                        <div class="h-3 w-16 animate-pulse rounded bg-slate-100 dark:bg-slate-800"></div>
                        <div class="h-3 w-16 animate-pulse rounded bg-slate-100 dark:bg-slate-800"></div>
                    </div>
                @endfor
            </div>
        </div>

        <div wire:loading.remove wire:target="setTab">
            @if (! $hasAccess)
                <x-insights.empty-state title="{{ __('Select a website to view insights') }}" body="{{ __('Use the website picker at the top of the app to choose a site. Insights update as its Search Console and indexing data syncs.') }}" />
            @elseif ($tab === 'cannibalization')
                <x-insights.card title="{{ __('Keyword cannibalization') }}" description="{{ __('Queries where two or more of your pages split clicks — consolidate content or re-target the weaker URLs.') }}">
                    @if (empty($data['cannibalization']))
                        <x-insights.empty-state title="{{ __('No cannibalization detected') }}" body="{{ __('No queries in the last 28 days are splitting clicks across multiple pages. Either your information architecture is clean or there\'s not yet enough GSC data — re-check after a full sync.') }}" />
                    @else
                        <x-insights.scroll-area>
                            <table class="min-w-full text-left text-xs">
                                <thead class="border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Query') }}</th>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Primary page') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Pages') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Clicks') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Impr.') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold" title="{{ __('Monthly search volume for this query') }}">{{ __('Volume/mo') }}</th>
                                        <th scope="col" class="py-2 font-semibold">{{ __('Competing pages (share %)') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($data['cannibalization'] as $row)
                                        <tr>
                                            <td class="py-2 pr-3 font-medium text-slate-800 dark:text-slate-200">{{ $row['query'] }}<x-keyword-language :language="$row['language'] ?? null" /></td>
                                            <td class="py-2 pr-3 max-w-[280px] truncate text-slate-600 dark:text-slate-300" title="{{ $row['primary_page'] }}">{{ $row['primary_page'] }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ $row['page_count'] }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($row['total_clicks']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($row['total_impressions']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">
                                                @if (! empty($row['search_volume']))
                                                    <span class="font-semibold text-amber-600 dark:text-amber-400">{{ number_format($row['search_volume']) }}</span>
                                                @else
                                                    <span class="text-slate-400">—</span>
                                                @endif
                                            </td>
                                            <td class="py-2">
                                                <ul class="space-y-0.5">
                                                    @foreach ($row['competing_pages'] as $p)
                                                        <li class="max-w-[360px] truncate text-slate-500 dark:text-slate-400" title="{{ $p['page'] }}">
                                                            <span class="tabular-nums font-semibold text-amber-600 dark:text-amber-400">{{ $p['share'] }}%</span>
                                                            <span class="ms-2">{{ $p['page'] }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-insights.scroll-area>
                    @endif
                </x-insights.card>
            @elseif ($tab === 'striking_distance')
                <x-insights.card title="{{ __('Striking-distance keywords') }}" description="{{ __('Queries at positions 5–20 with strong impressions and below-curve CTR — the fastest wins on your content calendar.') }}">
                    @if (empty($data['striking_distance']))
                        <x-insights.empty-state title="{{ __('No striking-distance opportunities yet') }}" body="{{ __('We look for queries with at least 200 impressions ranking between #5 and #20. As your GSC history grows, qualifying keywords will appear here with a priority score.') }}" />
                    @else
                        <x-insights.scroll-area>
                            <table class="min-w-full text-left text-xs">
                                <thead class="border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Query') }}</th>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Ranking URL') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Volume/mo') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Position') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Impressions') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Clicks') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('CTR') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold" title="{{ __('Opportunity score: impressions weighted by how close the position is to page one') }}">{{ __('Score') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold"><span class="sr-only">{{ __('Fix') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($data['striking_distance'] as $row)
                                        <tr>
                                            <td class="py-2 pr-3 font-medium text-slate-800 dark:text-slate-200">{{ $row['query'] }}<x-keyword-language :language="$row['language'] ?? null" /></td>
                                            <td class="py-2 pr-3 max-w-[280px] truncate text-slate-600 dark:text-slate-300" title="{{ $row['page'] ?? '' }}">
                                                @if (! empty($row['page']))
                                                    <a href="{{ $row['page'] }}" target="_blank" rel="noopener" class="text-orange-600 hover:underline dark:text-orange-400">{{ $row['page'] }}</a>
                                                    <span class="ms-1 text-[10px] text-slate-400">#{{ $row['page_position'] ?? $row['position'] }}</span>
                                                @else
                                                    <span class="text-slate-400">—</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pe-3 text-end tabular-nums">
                                                @if ($row['search_volume'] !== null)
                                                    {{ number_format($row['search_volume']) }}
                                                @else
                                                    <span class="text-slate-400">—</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ $row['position'] }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($row['impressions']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($row['clicks']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ $row['ctr'] }}%</td>
                                            <td class="py-2 pe-3 text-end tabular-nums font-semibold text-orange-600 dark:text-orange-400">
                                                {{ $row['score'] }}
                                            </td>
                                            <td class="py-2 pe-3 text-end">
                                                @if (! empty($row['page']))
                                                    <a href="{{ route('keywords.fix', ['keyword' => $row['query'], 'page' => $row['page']]) }}" wire:navigate
                                                        class="inline-flex items-center gap-1 rounded-md bg-orange-600 px-2 py-1 text-[11px] font-semibold text-white transition hover:bg-orange-500">{{ __('Fix') }} →</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-insights.scroll-area>
                    @endif
                </x-insights.card>
            @elseif ($tab === 'audit_performance')
                <x-insights.card title="{{ __('Audit vs. performance') }}" description="{{ __('Pages with poor Core Web Vitals scores that still attract real search impressions — technical debt measurably costing traffic.') }}">
                    @if (empty($data['audit_performance']))
                        <x-insights.empty-state title="{{ __('No underperforming audited pages') }}" body="{{ __('Every audited page is scoring well on Core Web Vitals, or you haven\'t audited many pages yet. Run a page audit from the Audits tab to populate this list.') }}" />
                    @else
                        <x-insights.scroll-area>
                            <table class="min-w-full text-left text-xs">
                                <thead class="border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Page') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Mobile') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold hidden md:table-cell">{{ __('Desktop') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold hidden md:table-cell">{{ __('LCP (mob)') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold hidden lg:table-cell">{{ __('CLS (mob)') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Impr. (28d)') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold hidden sm:table-cell">{{ __('Clicks (28d)') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($data['audit_performance'] as $row)
                                        <tr>
                                            <td class="py-2 pr-3 max-w-[320px] truncate text-slate-800 dark:text-slate-200" title="{{ $row['page'] }}">{{ $row['page'] }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">
                                                @if ($row['performance_score_mobile'] === null)
                                                    <span class="text-slate-400">—</span>
                                                @else
                                                    <span @class(['font-semibold', 'text-red-600 dark:text-red-400' => $row['performance_score_mobile'] < 50, 'text-amber-600 dark:text-amber-400' => $row['performance_score_mobile'] >= 50])>{{ $row['performance_score_mobile'] }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pe-3 text-end tabular-nums hidden md:table-cell">{{ $row['performance_score_desktop'] ?? '—' }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums hidden md:table-cell">{{ $row['lcp_ms_mobile'] !== null ? number_format($row['lcp_ms_mobile']).'ms' : '—' }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums hidden lg:table-cell">{{ $row['cls_mobile'] !== null ? number_format($row['cls_mobile'], 3) : '—' }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($row['impressions']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums hidden sm:table-cell">{{ number_format($row['clicks']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-insights.scroll-area>
                    @endif
                </x-insights.card>
            @elseif ($tab === 'content_decay')
                <x-insights.card title="{{ __('Content decay') }}" description="{{ __('Pages losing clicks 28d-over-28d while still attracting impressions. The indexing verdict tells you whether it\'s ranking decay or de-indexing.') }}">
                    @if (empty($data['content_decay']['pages']))
                        <x-insights.empty-state title="{{ __('No decay detected') }}" body="{{ __('Either every high-impression page is holding steady, or we don\'t have two full 28-day windows of GSC history yet. Once the baseline fills in, declining pages will appear here.') }}" />
                    @else
                        <x-insights.scroll-area>
                            <table class="min-w-full text-left text-xs">
                                <thead class="border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Page') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Clicks (28d)') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold hidden md:table-cell">{{ __('Prev 28d') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">Δ 28d</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold hidden lg:table-cell">YoY</th>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Verdict') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($data['content_decay']['pages'] as $row)
                                        <tr>
                                            <td class="py-2 pr-3 max-w-[320px] truncate text-slate-800 dark:text-slate-200" title="{{ $row['page'] }}">
                                                {{ $row['page'] }}
                                                @if (($row['decay_reason'] ?? null) === 'market_decline')
                                                    <span class="ms-1.5 inline-flex rounded-full bg-amber-100 px-1.5 py-px text-[9px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400" title="{{ __('Top queries for this page are declining market-wide — demand is shrinking, not your page\'s ranking') }}">{{ __('market decline') }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($row['current_clicks']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums hidden md:table-cell">{{ number_format($row['previous_clicks']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums font-semibold text-red-600 dark:text-red-400">{{ $row['clicks_change_percent'] }}%</td>
                                            <td class="py-2 pe-3 text-end tabular-nums hidden lg:table-cell">
                                                @if (! $data['content_decay']['has_yoy_history'])
                                                    <span class="text-slate-400 dark:text-slate-500">—</span>
                                                @elseif ($row['yoy_change_percent'] === null)
                                                    <span class="text-slate-400 dark:text-slate-500">—</span>
                                                @else
                                                    <span @class([
                                                        'font-semibold',
                                                        'text-red-600 dark:text-red-400' => $row['yoy_change_percent'] < 0,
                                                        'text-emerald-600 dark:text-emerald-400' => $row['yoy_change_percent'] >= 0,
                                                    ])>{{ $row['yoy_change_percent'] }}%</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-3">
                                                @if ($row['verdict'] === 'PASS')
                                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">PASS</span>
                                                @elseif ($row['verdict'])
                                                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400">{{ $row['verdict'] }}</span>
                                                @else
                                                    <span class="text-slate-400 dark:text-slate-500">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-insights.scroll-area>
                        @if (! $data['content_decay']['has_yoy_history'])
                            <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">{{ __('YoY column will populate once you have 13+ months of Search Console history.') }}</p>
                        @endif
                    @endif
                </x-insights.card>
            @elseif ($tab === 'quick_wins')
                <x-insights.card title="{{ __('Quick wins') }}" description="{{ __('Low-competition keywords with real search volume where you either don\'t rank or rank outside the top 10. Sorted by search volume and how winnable they look.') }}">
                    @if (empty($data['quick_wins']))
                        <x-insights.empty-state title="{{ __('No quick wins surfaced yet') }}" body="{{ __('Either our keyword intelligence hasn\'t filled in for your queries yet, or everything with real volume is already in your top 10. Check back in a day or two as the cache warms up.') }}" />
                    @else
                        <x-insights.scroll-area>
                            <table class="min-w-full text-left text-xs">
                                <thead class="border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Keyword') }}</th>
                                        <th scope="col" class="py-2 pe-3 font-semibold">{{ __('Ranking URL') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Volume/mo') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold hidden md:table-cell">{{ __('Comp.') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold">{{ __('Current pos') }}</th>
                                        <th scope="col" class="py-2 pe-3 text-end font-semibold" title="{{ __('Projected monthly clicks if this keyword reached position 3 (CTR curve x volume)') }}">{{ __('Est. clicks @#3') }}</th>
                                        <th scope="col" class="py-2 font-semibold">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($data['quick_wins'] as $row)
                                        <tr>
                                            <td class="py-2 pr-3 font-medium text-slate-800 dark:text-slate-200">{{ $row['keyword'] }}<x-keyword-language :language="$row['language'] ?? null" /></td>
                                            <td class="py-2 pr-3 max-w-[280px] truncate text-slate-600 dark:text-slate-300" title="{{ $row['current_page'] ?? '' }}">
                                                @if (! empty($row['current_page']))
                                                    <a href="{{ $row['current_page'] }}" target="_blank" rel="noopener" class="text-orange-600 hover:underline dark:text-orange-400">{{ $row['current_page'] }}</a>
                                                    @if ($row['current_position'] !== null)
                                                        <span class="ms-1 text-[10px] text-slate-400">#{{ $row['current_position'] }}</span>
                                                    @endif
                                                @else
                                                    <span class="text-slate-400">—</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pe-3 text-end tabular-nums">{{ number_format($row['search_volume']) }}</td>
                                            <td class="py-2 pe-3 text-end tabular-nums hidden md:table-cell">
                                                @if ($row['competition'] !== null)
                                                    {{ number_format($row['competition'] * 100, 0) }}%
                                                @else
                                                    <span class="text-slate-400">—</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pe-3 text-end tabular-nums">
                                                @if ($row['current_position'] !== null)
                                                    #{{ $row['current_position'] }}
                                                @else
                                                    <span class="text-slate-400">{{ __('unranked') }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pe-3 text-end tabular-nums font-semibold text-emerald-600 dark:text-emerald-400">
                                                {{ number_format((int) round($row['search_volume'] * 0.11)) }}
                                            </td>
                                            <td class="py-2">
                                                @php
                                                    $_auditParams = ['targetKeyword' => $row['keyword']];
                                                    if (! empty($row['current_page'])) {
                                                        $_auditParams['pageUrl'] = $row['current_page'];
                                                    }
                                                @endphp
                                                <a href="{{ route('custom-audit.index') }}?{{ http_build_query($_auditParams) }}" wire:navigate class="font-semibold text-orange-600 hover:underline dark:text-orange-400">
                                                    @if (! empty($row['current_page']))
                                                        {{ __('Audit current page') }} →
                                                    @else
                                                        {{ __('Start new audit') }} →
                                                    @endif
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-insights.scroll-area>
                    @endif
                </x-insights.card>
            @endif
        </div>
    </div>
</div>
