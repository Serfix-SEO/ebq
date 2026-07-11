@php
    $tones = [
        'critical' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        'high' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        'growth' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    ];
    $tone = $tones[$meta['severity']] ?? $tones['high'];

    // Finding-level severity is a 4-tier scale (critical/high/medium/low), distinct
    // from the action-queue's 3-tier scale (critical/high/growth) used above.
    $findingTones = [
        'critical' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        'high' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        'medium' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
        'low' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    ];
@endphp

<div>
    {{-- Header --}}
    <div class="mb-5">
        <a href="{{ route('dashboard') }}" wire:navigate
            class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
            {{ __('Back to dashboard') }}
        </a>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ $meta['title'] }}</h1>
            <span class="rounded-full px-2 py-0.5 text-xs font-bold tabular-nums {{ $tone }}">{{ number_format($meta['count']) }}</span>
        </div>
        @if ($meta['description'])
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $meta['description'] }}</p>
        @endif
    </div>

    {{-- Filter bar --}}
    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        @if ($isCrawl && count($typeOptions) > 1)
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Type') }}</span>
                <select wire:model.live="type"
                    class="rounded-md border-slate-200 bg-white py-1.5 ps-2.5 pe-8 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <option value="">{{ __('All types') }}</option>
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($isCrawl)
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Severity') }}</span>
                <select wire:model.live="severity"
                    class="rounded-md border-slate-200 bg-white py-1.5 ps-2.5 pe-8 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <option value="">{{ __('All severities') }}</option>
                    <option value="critical">{{ __('Critical') }}</option>
                    <option value="high">{{ __('High') }}</option>
                    <option value="medium">{{ __('Medium') }}</option>
                    <option value="low">{{ __('Low') }}</option>
                </select>
            </label>
        @endif

        <label class="flex flex-1 flex-col gap-1" style="min-width: 12rem;">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Search') }}</span>
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="{{ __('Filter by URL or text…') }}"
                class="w-full rounded-md border-slate-200 bg-white py-1.5 px-2.5 text-sm text-slate-700 placeholder:text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
        </label>

        @if ($type !== '' || $severity !== '' || $q !== '')
            <button type="button" wire:click="clearFilters"
                class="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                {{ __('Clear') }}
            </button>
        @endif

        @if (! $grouped)
            <span class="ms-auto self-center text-xs text-slate-500 dark:text-slate-400" wire:loading.remove>
                {{ $rows->total() === 1 ? __(':count result', ['count' => number_format($rows->total())]) : __(':count results', ['count' => number_format($rows->total())]) }}
            </span>
            <span class="ms-auto self-center text-xs text-slate-400" wire:loading>{{ __('Loading…') }}</span>
        @endif
    </div>

    @if ($grouped)
        @php
            $crawlGroups = array_values(array_filter($groups, fn ($g) => ! $g['gsc_sourced']));
            $gscGroups = array_values(array_filter($groups, fn ($g) => $g['gsc_sourced']));
        @endphp
        {{-- Grouped-by-type breakdown (Semrush-style): one card per issue type, not
             one undifferentiated list — click a type to drill into its affected URLs. --}}
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            {{-- While a drill-down loads: dim + lock the list and swap the clicked
                 row's chevron for a spinner, so the click visibly "took". --}}
            <ul class="divide-y divide-slate-100 dark:divide-slate-800"
                wire:loading.class="pointer-events-none opacity-60" wire:target="selectType">
                @forelse ($crawlGroups as $g)
                    @php $gTone = $findingTones[$g['severity']] ?? $findingTones['low']; @endphp
                    <li>
                        <button type="button" wire:click="selectType('{{ $g['type'] }}')" wire:loading.attr="disabled" wire:target="selectType"
                            class="flex w-full cursor-pointer items-center gap-3 px-5 py-3.5 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/60">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums {{ $gTone }}">{{ number_format($g['count']) }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ $g['label'] }}</span>
                            <span class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide {{ $gTone }}">{{ __(ucfirst($g['severity'])) }}</span>
                            <svg wire:loading.remove wire:target="selectType('{{ $g['type'] }}')" class="h-3.5 w-3.5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            <svg wire:loading wire:target="selectType('{{ $g['type'] }}')" class="h-3.5 w-3.5 flex-none animate-spin text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75"></path></svg>
                        </button>
                    </li>
                @empty
                    <li class="px-5 py-12 text-center">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('Nothing to show') }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('No issue types match your filters.') }}</p>
                    </li>
                @endforelse
            </ul>
        </div>

        @if ($gscGroups !== [])
            {{-- Separated on purpose: these don't come from our crawl, they come from
                 Google Search Console history, which can lag the live site by days —
                 a page can show here as "missing from sitemap" when Google's copy of
                 the index is just stale, not because anything's actually wrong today. --}}
            <div class="mt-5 overflow-hidden rounded-xl border border-amber-200 bg-amber-50/40 shadow-sm dark:border-amber-500/20 dark:bg-amber-500/5">
                <div class="border-b border-amber-100 px-4 py-2.5 dark:border-amber-500/20">
                    <div class="text-[13px] font-semibold text-amber-900 dark:text-amber-200">{{ __('From Google Search Console') }}</div>
                    <p class="text-[11px] text-amber-700 dark:text-amber-300">{{ __('Based on Search Console history, not our own crawl — this data can be a few days old, so treat these as worth checking rather than confirmed.') }}</p>
                </div>
                <ul class="divide-y divide-amber-100/70 dark:divide-amber-500/10"
                    wire:loading.class="pointer-events-none opacity-60" wire:target="selectType">
                    @foreach ($gscGroups as $g)
                        @php $gTone = $findingTones[$g['severity']] ?? $findingTones['low']; @endphp
                        <li>
                            <button type="button" wire:click="selectType('{{ $g['type'] }}')" wire:loading.attr="disabled" wire:target="selectType"
                                class="flex w-full cursor-pointer items-center gap-3 px-5 py-3.5 text-left transition hover:bg-amber-100/40 dark:hover:bg-amber-500/10">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums {{ $gTone }}">{{ number_format($g['count']) }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900 dark:text-slate-100">{{ $g['label'] }}</span>
                                <span class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide {{ $gTone }}">{{ __(ucfirst($g['severity'])) }}</span>
                                <svg wire:loading.remove wire:target="selectType('{{ $g['type'] }}')" class="h-3.5 w-3.5 flex-none text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                <svg wire:loading wire:target="selectType('{{ $g['type'] }}')" class="h-3.5 w-3.5 flex-none animate-spin text-orange-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75"></path></svg>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        @if ($typeIsGscSourced)
            <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50/40 px-3 py-2 text-[11px] text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/5 dark:text-amber-300">
                {{ __('From Google Search Console history, not our own crawl — this can lag the live site by a few days, so verify before treating these as confirmed.') }}
            </div>
        @endif
        <button type="button" wire:click="clearFilters"
            class="mb-3 inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
            {{ __('Back to all issue types') }}
        </button>

        {{-- Rows --}}
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($rows as $row)
                    <li class="flex items-center gap-3 px-5 py-3.5">
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 truncate text-sm font-medium text-slate-900 dark:text-slate-100">
                                <span class="truncate">{{ $row['title'] }}</span>
                                @if (! empty($row['open_url']))
                                    <a href="{{ $row['open_url'] }}" target="_blank" rel="noopener"
                                        title="{{ __('Open :url in a new tab', ['url' => $row['open_url']]) }}"
                                        class="flex-none text-slate-400 transition hover:text-orange-600 dark:text-slate-500 dark:hover:text-orange-400">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    </a>
                                @endif
                            </p>
                            <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{{ $row['subtitle'] }}</p>
                            @if (! empty($row['metric']))
                                <p class="mt-0.5 text-[11px] font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $row['metric'] }}</p>
                            @endif
                        </div>
                        @if (! empty($row['fix_url']) && ! empty($row['fix_allowed']))
                            <a href="{{ $row['fix_url'] }}"
                                @if (! empty($row['fix_new_tab'])) target="_blank" rel="noopener" @else wire:navigate @endif
                                class="inline-flex flex-none items-center gap-1 rounded-md bg-orange-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-orange-500">
                                {{ __('Fix') }}
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </a>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-12 text-center">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('Nothing to show') }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('No issues match your filters.') }}</p>
                    </li>
                @endforelse
            </ul>
        </div>
    @endif

    @if (! $grouped && $rows->hasPages())
        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    @endif
</div>
