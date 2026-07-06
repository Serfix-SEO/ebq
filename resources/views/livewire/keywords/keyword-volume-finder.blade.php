@php
    $compMeta = function ($c) {
        if ($c === null) return ['—', 'text-slate-400'];
        return $c < 0.34 ? ['Low', 'text-emerald-600 dark:text-emerald-400']
            : ($c < 0.67 ? ['Medium', 'text-amber-600 dark:text-amber-400'] : ['High', 'text-rose-600 dark:text-rose-400']);
    };
@endphp
<div class="space-y-5" @if ($this->isPolling()) wire:poll.2000ms="poll" @endif>
    @unless ($usingFinder)
        {{-- Quota banner (credit-billed provider only) --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/40">
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Bulk-lookup volume, CPC, competition &amp; trend. Already-cached keywords are <span class="font-semibold text-emerald-600 dark:text-emerald-400">free</span> — you’re only charged for new ones.
            </p>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700">
                <svg class="h-3.5 w-3.5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                @if ($remaining === null)
                    Unlimited credits
                @else
                    {{ number_format($remaining) }}@if ($limit) / {{ number_format($limit) }}@endif credits left this month
                @endif
            </span>
        </div>
    @endunless

    {{-- Form --}}
    <form wire:submit="run" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="grid gap-4 sm:grid-cols-[1fr_12rem]">
            <div>
                <label for="kvf-keywords" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Keywords</label>
                <textarea id="kvf-keywords" wire:model="keywords" rows="6" placeholder="One keyword per line (or comma-separated)&#10;best seo tools&#10;keyword research&#10;rank tracker"
                    class="mt-1.5 block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm transition focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
                <p class="mt-1.5 text-[11px] text-slate-400 dark:text-slate-500">
                    @if ($usingFinder)
                        Up to 100 keywords. A lookup usually takes 20–60 seconds.
                    @else
                        Up to 100 keywords. Each new (uncached) keyword uses 1 Keywords Everywhere credit.
                    @endif
                </p>
            </div>
            <div class="flex flex-col gap-3">
                @if ($usingFinder)
                    <div>
                        <label for="kvf-location" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Location</label>
                        <input id="kvf-location" type="text" wire:model="location" list="kvf-locations" placeholder="United States"
                            class="mt-1.5 block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
                        <datalist id="kvf-locations">
                            @foreach ($locationNames as $name)
                                <option value="{{ $name }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-1 text-[10px] text-slate-400 dark:text-slate-500">Any country, region or city. Use “All” for worldwide.</p>
                    </div>
                    <div>
                        <label for="kvf-language" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Language</label>
                        <select id="kvf-language" wire:model="language"
                            class="mt-1.5 block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            @foreach ($languages as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label for="kvf-country" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Country</label>
                        <select id="kvf-country" wire:model="country"
                            class="mt-1.5 block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                            @foreach ($countries as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button type="submit" wire:loading.attr="disabled" wire:target="run"
                    class="mt-auto inline-flex items-center justify-center gap-2 rounded-lg bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-60">
                    <svg wire:loading wire:target="run" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span wire:loading.remove wire:target="run">Get volume</span>
                    <span wire:loading wire:target="run">Fetching…</span>
                </button>
            </div>
        </div>

        @if ($errorMessage)
            <div class="mt-4 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-xs text-rose-700 dark:border-rose-900/40 dark:bg-rose-500/10 dark:text-rose-300" role="alert">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                <span>{{ $errorMessage }}</span>
            </div>
        @endif
    </form>

    {{-- In-flight (finder async) --}}
    @if ($this->isPolling())
        <div class="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800 dark:border-amber-900/40 dark:bg-amber-500/10 dark:text-amber-300">
            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Looking up volumes — this usually takes 20–60 seconds. Results will appear automatically.
        </div>
    @endif

    @if ($trackNotice)
        <p class="text-xs text-emerald-600 dark:text-emerald-400">{{ $trackNotice }}</p>
    @endif

    {{-- Results --}}
    @if ($hasRun && count($results))
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:border-slate-800">
                        <th class="px-4 py-2.5">Keyword</th>
                        <th class="px-4 py-2.5 text-right">Volume / mo</th>
                        <th class="px-4 py-2.5 text-right">CPC</th>
                        <th class="px-4 py-2.5 text-right">Competition</th>
                        <th class="px-4 py-2.5">Trend</th>
                        <th class="px-4 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($results as $r)
                        @php
                            [$compLabel, $compClass] = $compMeta($r['competition']);
                            $series = collect($r['trend'])->filter(fn ($t) => is_array($t))->map(fn ($t) => (int) ($t['value'] ?? 0))->values();
                            $peak = $series->max() ?: 1;
                        @endphp
                        <tr class="transition hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">
                                {{ $r['keyword'] }}
                                @if (! $usingFinder && $r['from_cache'])
                                    <span class="ml-1.5 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400" title="Served from cache — no credit used">cached</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $r['volume'] !== null ? number_format($r['volume']) : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $r['cpc'] !== null ? $r['currency'].' '.number_format((float) $r['cpc'], 2) : '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold {{ $compClass }}">{{ $compLabel }}</td>
                            <td class="px-4 py-3">
                                @if ($series->count())
                                    <div class="flex h-7 items-end gap-px">
                                        @foreach ($series as $v)
                                            <div class="w-1 rounded-t bg-orange-400/80" style="height: {{ max(2, (int) round(($v / $peak) * 26)) }}px"></div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-2 text-[11px]">
                                    <button type="button" wire:click="sendToIdeas(@js($r['keyword']))" class="text-orange-600 hover:underline dark:text-orange-400">Ideas</button>
                                    <button type="button" wire:click="track(@js($r['keyword']))" class="text-slate-600 hover:underline dark:text-slate-300">Track</button>
                                    @if (auth()->user()?->hasFeatureAccess('audits', (string) session('current_website_id', '')))
                                        <a href="{{ route('keywords.fix', ['keyword' => $r['keyword']]) }}" class="text-slate-600 hover:underline dark:text-slate-300">Brief</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-slate-400 dark:text-slate-500">
            @if ($usingFinder)
                Monthly search volume, competition and top-of-page bids from Google Keyword Planner.
            @else
                Data from Keywords Everywhere (Google Keyword Planner). Cached values stay fresh for {{ (int) config('services.keywords_everywhere.fresh_days', 30) }} days and are shared across Keywords, Rank Tracking and Search Console imports.
            @endif
        </p>
    @elseif ($hasRun && ! $this->isPolling())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/60 px-4 py-12 text-center dark:border-slate-700 dark:bg-slate-900/40">
            <p class="text-sm text-slate-500 dark:text-slate-400">No results to show.</p>
        </div>
    @endif
</div>
